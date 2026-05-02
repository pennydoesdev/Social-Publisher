/**
 * Social Publisher — Block editor sidebar panel.
 *
 * Adds a Document sidebar panel with:
 *  - Skip toggle (synced with the _social_publisher_skip post meta).
 *  - "Generate drafts now" button -> POST /social-publisher/v1/regenerate/{id}.
 *  - Last-run status readout.
 *
 * Works on any post — including past, already-published ones.
 */
( function ( wp ) {
	if ( ! wp || ! wp.plugins || ! wp.editPost ) {
		return;
	}

	var registerPlugin              = wp.plugins.registerPlugin;
	var PluginDocumentSettingPanel  = wp.editPost.PluginDocumentSettingPanel;
	var el                          = wp.element.createElement;
	var Fragment                    = wp.element.Fragment;
	var useState                    = wp.element.useState;
	var useEffect                   = wp.element.useEffect;
	var useSelect                   = wp.data.useSelect;
	var useDispatch                 = wp.data.useDispatch;
	var ToggleControl               = wp.components.ToggleControl;
	var Button                      = wp.components.Button;
	var Notice                      = wp.components.Notice;
	var Spinner                     = wp.components.Spinner;
	var apiFetch                    = wp.apiFetch;
	var __                          = wp.i18n.__;

	var META_SKIP = '_social_publisher_skip';

	function formatTime( unix ) {
		if ( ! unix ) { return ''; }
		try { return new Date( unix * 1000 ).toLocaleString(); } catch ( e ) { return ''; }
	}

	function Panel() {
		var postId = useSelect( function ( select ) {
			return select( 'core/editor' ).getCurrentPostId();
		}, [] );

		var meta = useSelect( function ( select ) {
			return select( 'core/editor' ).getEditedPostAttribute( 'meta' ) || {};
		}, [] );

		var editPost = useDispatch( 'core/editor' ).editPost;

		var skip = !! meta[ META_SKIP ];

		var stateInit = { loading: false, message: null, isError: false, status: null };
		var stateTuple = useState( stateInit );
		var state = stateTuple[ 0 ];
		var setState = stateTuple[ 1 ];

		useEffect( function () {
			if ( ! postId ) { return; }
			apiFetch( { path: '/social-publisher/v1/status/' + postId } )
				.then( function ( res ) { setState( function ( s ) { return Object.assign( {}, s, { status: res } ); } ); } )
				.catch( function () { /* silent */ } );
		}, [ postId ] );

		function setSkip( next ) {
			var nextMeta = {};
			nextMeta[ META_SKIP ] = !! next;
			editPost( { meta: nextMeta } );
		}

		function generate() {
			if ( ! postId ) { return; }
			setState( { loading: true, message: null, isError: false, status: state.status } );
			apiFetch( {
				path: '/social-publisher/v1/regenerate/' + postId,
				method: 'POST',
			} ).then( function ( res ) {
				if ( res && res.ok ) {
					setState( {
						loading: false,
						isError: false,
						message: __( 'Drafts created in Publer.', 'social-publisher' ),
						status: { run_at: Math.floor( Date.now() / 1000 ), summary: res.summary, skip: skip },
					} );
				} else {
					setState( {
						loading: false,
						isError: true,
						message: ( res && res.error ) ? res.error : __( 'Generation failed.', 'social-publisher' ),
						status: { run_at: Math.floor( Date.now() / 1000 ), summary: ( res && res.summary ) || null, skip: skip },
					} );
				}
			} ).catch( function ( err ) {
				setState( {
					loading: false,
					isError: true,
					message: ( err && err.message ) ? err.message : __( 'Request failed.', 'social-publisher' ),
					status: state.status,
				} );
			} );
		}

		var summary = state.status && state.status.summary;
		var runAt   = state.status && state.status.run_at;

		var summaryNode = null;
		if ( summary ) {
			summaryNode = el( 'div', { style: { marginTop: '8px', fontSize: '12px' } }, [
				el( 'div', { key: 'r' }, [
					el( 'strong', { key: 'l' }, __( 'Last run: ', 'social-publisher' ) ),
					formatTime( runAt ),
				] ),
				el( 'div', { key: 'd' }, [
					el( 'strong', { key: 'l' }, __( 'Drafts: ', 'social-publisher' ) ),
					String( summary.drafts || 0 ),
				] ),
				summary.platforms && summary.platforms.length
					? el( 'div', { key: 'p' }, [
						el( 'strong', { key: 'l' }, __( 'Platforms: ', 'social-publisher' ) ),
						summary.platforms.join( ', ' ),
					] )
					: null,
				summary.failures && summary.failures.length
					? el( 'div', { key: 'f', style: { color: '#b32d2e' } }, [
						el( 'strong', { key: 'l' }, __( 'Failures: ', 'social-publisher' ) ),
						String( summary.failures.length ),
					] )
					: null,
			] );
		}

		return el(
			PluginDocumentSettingPanel,
			{
				name: 'social-publisher-panel',
				title: __( 'Social Publisher', 'social-publisher' ),
				className: 'social-publisher-panel',
			},
			el( Fragment, {}, [
				el( ToggleControl, {
					key: 'skip',
					label: __( 'Skip social publishing for this post', 'social-publisher' ),
					checked: skip,
					onChange: setSkip,
				} ),
				el( Button, {
					key: 'btn',
					variant: 'primary',
					isBusy: state.loading,
					disabled: state.loading || ! postId,
					onClick: generate,
				}, state.loading
					? [ el( Spinner, { key: 's' } ), __( 'Generating…', 'social-publisher' ) ]
					: __( 'Generate drafts now', 'social-publisher' )
				),
				state.message
					? el( Notice, {
						key: 'msg',
						status: state.isError ? 'error' : 'success',
						isDismissible: true,
						onRemove: function () { setState( function ( s ) { return Object.assign( {}, s, { message: null } ); } ); },
					}, state.message )
					: null,
				summaryNode,
				el( 'p', { key: 'help', style: { marginTop: '8px', fontStyle: 'italic' } },
					__( 'Works on any post, including past ones. All Publer posts are created as drafts.', 'social-publisher' )
				),
			] )
		);
	}

	registerPlugin( 'social-publisher', {
		render: function () { return el( Panel ); },
		icon: 'share',
	} );
} )( window.wp );
