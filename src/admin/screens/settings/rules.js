import {
	Button,
	CheckboxControl,
	Modal,
	Notice,
	SelectControl,
	__experimentalConfirmDialog as ConfirmDialog,
	__experimentalHStack as HStack,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { dateI18n, getSettings } from '@wordpress/date';
import {
	RawHTML,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import { decodeEntities } from '@wordpress/html-entities';
import { __, sprintf } from '@wordpress/i18n';

import {
	ErrorNotice,
	Loading,
	RecordLink,
	SidePanel,
	SortableList,
	moveItem,
	usePanelStack,
} from '../../components';
import {
	useDiscardGuard,
	useNotify,
	useRecord,
	useUnsavedChanges,
} from '../../hooks';
import { request } from '../../utils/api';
import { config, languageLabel, localized, localizedFieldLabel } from '../../utils/config';
import Section from './section';
import SettingsPage from './settings-page';

const PATH = '/settings/rules';
const ruleLabel = ( index ) =>
	sprintf(
		/* translators: %d: rule position. */
		__( 'Rule %d', 'open-bulk-buying' ),
		index + 1
	);
const languageOptions = [
	{ label: __( 'Default', 'open-bulk-buying' ), value: 'default' },
	...config.translationLanguages.map( ( language ) => ( {
		label: languageLabel( language ),
		value: language,
	} ) ),
];

function RichEditor( { id, label, value, onChange } ) {
	const change = useRef( onChange );
	change.current = onChange;
	const [ initialValue ] = useState( value );
	const [ error, setError ] = useState( null );

	useEffect( () => {
		if ( ! window.wp?.editor?.initialize ) {
			setError( {
				message: __(
					'The rich-text editor could not be loaded. Reload this page to try again.',
					'open-bulk-buying'
				),
			} );
			return;
		}
		window.wp.editor.initialize( id, {
			quicktags: false,
			mediaButtons: false,
			tinymce: {
				menubar: false,
				plugins: 'lists link paste wordpress',
				toolbar1:
					'formatselect bold italic link bullist blockquote undo redo removeformat',
				toolbar2: '',
				height: 280,
				setup: ( editor ) => {
					editor.on( 'init', () => {
						editor.iframeElement.setAttribute( 'title', label );
					} );
					editor.on( 'input change undo redo', () =>
						change.current( editor.getContent() )
					);
				},
			},
		} );
		return () => window.wp.editor.remove( id );
	}, [ id, label ] );

	return (
		<div className="openbb-rules__editor">
			<ErrorNotice error={ error } />
			<label htmlFor={ id }>{ label }</label>
			<textarea
				id={ id }
				defaultValue={ initialValue }
				onChange={ ( event ) => change.current( event.target.value ) }
				rows={ 12 }
			/>
		</div>
	);
}

function PublishedRules( { version } ) {
	const [ language, setLanguage ] = useState( 'default' );
	const languages = [
		...new Set( [
			'default',
			...config.translationLanguages,
			...version.content.flatMap( Object.keys ),
		] ),
	];
	return (
		<VStack spacing={ 4 }>
			<p>
				{ dateI18n(
					getSettings().formats.datetime,
					version.published_at
				) }
				{ ' — ' }
				{ version.published_by }
			</p>
			<p>
				{ version.requires_reacceptance
					? __( 'Requires member acceptance.', 'open-bulk-buying' )
					: __(
							'Does not require renewed acceptance.',
							'open-bulk-buying'
					  ) }
			</p>
			{ languages.length > 1 && (
				<SelectControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Content language', 'open-bulk-buying' ) }
					value={ language }
					options={ languages.map( ( code ) => ( {
						value: code,
						label:
							code === 'default'
								? __( 'Default', 'open-bulk-buying' )
								: languageLabel( code ),
					} ) ) }
					onChange={ setLanguage }
					help={ __(
						'Missing translations use the default text.',
						'open-bulk-buying'
					) }
				/>
			) }
			<ol className="openbb-rules__published">
				{ version.content.map( ( item, index ) => (
					<li key={ index }>
						<RawHTML>{ item[ language ] || item.default }</RawHTML>
					</li>
				) ) }
			</ol>
		</VStack>
	);
}

function VersionHistory( { versions, onClose } ) {
	return (
		<SidePanel
			title={ __( 'Version history', 'open-bulk-buying' ) }
			onClose={ onClose }
		>
			{ ! versions.length && (
				<p>
					{ __(
						'No versions have been published.',
						'open-bulk-buying'
					) }
				</p>
			) }
			<ul className="openbb-rules__history">
				{ versions.map( ( version, index ) => (
					<li key={ version.id }>
						<RecordLink type="rules-version" id={ version.id }>
							{ sprintf(
								/* translators: %d: published version ID. */
								__( 'View version %d', 'open-bulk-buying' ),
								version.id
							) }
						</RecordLink>
						{ index === 0 && (
							<span className="openbb-badge">
								{ __( 'Current', 'open-bulk-buying' ) }
							</span>
						) }
						<span>
							{ dateI18n(
								getSettings().formats.datetime,
								version.published_at
							) }
							{ ' — ' }
							{ version.published_by }
						</span>
						<span>
							{ version.requires_reacceptance
								? __(
										'Acceptance required',
										'open-bulk-buying'
								  )
								: __(
										'No renewed acceptance',
										'open-bulk-buying'
								  ) }
						</span>
					</li>
				) ) }
			</ul>
		</SidePanel>
	);
}

// History navigation is read-only and never replaces the draft editor.
const navigateHistory = ( navigate ) => navigate();

function RulesEditor( { record, setRecord, onHistory } ) {
	const nextId = useRef( 0 );
	const toItems = ( content ) =>
		content.map( ( text ) => ( { id: ++nextId.current, text } ) );
	const [ items, setItems ] = useState( () => toItems( record.draft ) );
	const [ editing, setEditing ] = useState( null );
	const [ language, setLanguage ] = useState( 'default' );
	const [ confirm, setConfirm ] = useState( null );
	const [ reaccept, setReaccept ] = useState( true );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ guard, discardDialog ] = useDiscardGuard();
	const notify = useNotify();
	const content = items.map( ( item ) => item.text );
	const dirty = JSON.stringify( content ) !== JSON.stringify( record.draft );
	useUnsavedChanges( dirty );
	const selected = items.find( ( item ) => item.id === editing );

	const update = async ( action ) => {
		setBusy( true );
		setError( null );
		try {
			const methods = {
				reload: 'GET',
				save: 'PUT',
				publish: 'POST',
				discard: 'POST',
			};
			const saved = await request(
				action === 'reload'
					? PATH
					: PATH + ( action === 'save' ? '' : `/${ action }` ),
				{
					method: methods[ action ],
					...( action === 'reload'
						? {}
						: {
								data: {
									revision: record.revision,
									...( action === 'save' ? { content } : {} ),
									...( action === 'publish'
										? { requires_reacceptance: reaccept }
										: {} ),
								},
						  } ),
				}
			);
			setRecord( saved );
			setItems( toItems( saved.draft ) );
			setReaccept( true );
			const messages = {
				publish: __( 'Rules published.', 'open-bulk-buying' ),
				save: __( 'Draft saved.', 'open-bulk-buying' ),
				reload: __( 'Rules reloaded.', 'open-bulk-buying' ),
				discard: __( 'Draft discarded.', 'open-bulk-buying' ),
			};
			notify.success( messages[ action ] );
		} catch ( err ) {
			setError( err );
		} finally {
			setBusy( false );
		}
	};

	return (
		<VStack spacing={ 6 } className="openbb-settings openbb-rules">
			<Section
				title={ __( 'Rules draft', 'open-bulk-buying' ) }
				actions={
					<HStack expanded={ false }>
						<Button variant="tertiary" onClick={ onHistory }>
							{ __( 'Version history', 'open-bulk-buying' ) }
						</Button>
						<Button
							variant="secondary"
							disabled={ busy }
							onClick={ () => {
								const id = ++nextId.current;
								setItems( [
									...items,
									{ id, text: { default: '' } },
								] );
								setLanguage( 'default' );
								setEditing( id );
							} }
						>
							{ __( 'Add rule', 'open-bulk-buying' ) }
						</Button>
					</HStack>
				}
			>
				<p>
					{ __(
						'Rules appear to members to agree to when they sign up. Save your draft, then publish it when ready. Published versions cannot be edited or deleted.',
						'open-bulk-buying'
					) }
				</p>
				<ErrorNotice
					error={ error }
					onDismiss={ () => setError( null ) }
				/>
				{ error?.code === 'openbb_rules_stale' && (
					<Button
						variant="secondary"
						disabled={ busy }
						onClick={ () => guard( () => update( 'reload' ) ) }
					>
						{ __( 'Reload rules', 'open-bulk-buying' ) }
					</Button>
				) }
				{ ! items.length && (
					<p>
						{ __(
							'No rules yet. Add your first rule to get started.',
							'open-bulk-buying'
						) }
					</p>
				) }
				<SortableList
					items={ items }
					getKey={ ( item ) => item.id }
					getLabel={ ( item ) => ruleLabel( items.indexOf( item ) ) }
					disabled={ busy }
					onMove={ ( from, to ) =>
						setItems( moveItem( items, from, to ) )
					}
					renderItem={ ( item, index ) => (
						<div className="openbb-rules__card">
							<strong>{ ruleLabel( index ) }</strong>
							<p>
								{ decodeEntities(
									localized( item.text ).replace( /<[^>]*>/g, ' ' )
								)
									.replace( /\s+/g, ' ' )
									.trim() ||
									__( 'Empty rule', 'open-bulk-buying' ) }
							</p>
							<HStack justify="flex-start">
								<Button
									variant="secondary"
									disabled={ busy }
									onClick={ () => {
										setLanguage( 'default' );
										setEditing( item.id );
									} }
								>
									{ sprintf(
										/* translators: %s: rule label. */
										__( 'Edit %s', 'open-bulk-buying' ),
										ruleLabel( index )
									) }
								</Button>
								<Button
									variant="tertiary"
									isDestructive
									disabled={ busy }
									onClick={ () =>
										setConfirm( {
											action: 'remove',
											id: item.id,
										} )
									}
								>
									{ sprintf(
										/* translators: %s: rule label. */
										__( 'Remove %s', 'open-bulk-buying' ),
										ruleLabel( index )
									) }
								</Button>
							</HStack>
						</div>
					) }
				/>
				<HStack justify="flex-start" wrap>
					<Button
						variant="primary"
						isBusy={ busy }
						disabled={ busy || ! dirty }
						onClick={ () => update( 'save' ) }
					>
						{ __( 'Save draft', 'open-bulk-buying' ) }
					</Button>
					<Button
						variant="secondary"
						disabled={ busy || ( ! dirty && ! record.unpublished ) }
						onClick={ () => setConfirm( { action: 'discard' } ) }
					>
						{ __( 'Discard draft', 'open-bulk-buying' ) }
					</Button>
				</HStack>
				{ dirty && (
					<Notice status="info" isDismissible={ false }>
						{ __(
							'Save the draft before publishing.',
							'open-bulk-buying'
						) }
					</Notice>
				) }
				<CheckboxControl
					__nextHasNoMarginBottom
					label={ __(
						'Require existing members to accept this version on their next order',
						'open-bulk-buying'
					) }
					help={ __(
						'The first version always requires acceptance. Translation-only changes never require renewed acceptance.',
						'open-bulk-buying'
					) }
					checked={ ! record.versions.length || reaccept }
					disabled={ busy || ! record.versions.length }
					onChange={ setReaccept }
				/>
				<Button
					variant="primary"
					disabled={
						busy ||
						dirty ||
						! items.length ||
						( ! record.unpublished &&
							( ! reaccept ||
								record.versions[ 0 ]?.requires_reacceptance ) )
					}
					onClick={ () => setConfirm( { action: 'publish' } ) }
				>
					{ __( 'Publish rules', 'open-bulk-buying' ) }
				</Button>
			</Section>
			{ selected && (
				<Modal
					title={ ruleLabel( items.indexOf( selected ) ) }
					size="large"
					onRequestClose={ () => setEditing( null ) }
				>
					<VStack spacing={ 4 }>
						{ languageOptions.length > 1 && (
							<SelectControl
								__next40pxDefaultSize
								__nextHasNoMarginBottom
								label={ __(
									'Editing language',
									'open-bulk-buying'
								) }
								value={ language }
								options={ languageOptions }
								onChange={ setLanguage }
								help={ __(
									'Leave a translation empty to use the default text.',
									'open-bulk-buying'
								) }
							/>
						) }
						<RichEditor
							key={ `${ selected.id }-${ language }` }
							id={ `openbb-rule-${ selected.id }-${ language }` }
							label={ localizedFieldLabel(
								__( 'Text', 'open-bulk-buying' ),
								language
							) }
							value={ selected.text[ language ] || '' }
							onChange={ ( html ) =>
								setItems( ( previous ) =>
									previous.map( ( item ) =>
										item.id === selected.id
											? {
													...item,
													text: {
														...item.text,
														[ language ]: html,
													},
											  }
											: item
									)
								)
							}
						/>
						<p>
							{ __(
								'Changes are kept in this draft. Use Save draft after closing the editor.',
								'open-bulk-buying'
							) }
						</p>
						<Button
							variant="primary"
							onClick={ () => setEditing( null ) }
						>
							{ __( 'Done', 'open-bulk-buying' ) }
						</Button>
					</VStack>
				</Modal>
			) }
			{ confirm && (
				<ConfirmDialog
					isOpen
					onCancel={ () => setConfirm( null ) }
					onConfirm={ () => {
						setConfirm( null );
						if ( confirm.action === 'remove' ) {
							setItems(
								items.filter(
									( item ) => item.id !== confirm.id
								)
							);
						} else {
							update( confirm.action );
						}
					} }
					confirmButtonText={
						confirm.action === 'publish'
							? __( 'Publish', 'open-bulk-buying' )
							: __( 'Discard', 'open-bulk-buying' )
					}
				>
					{
						{
							publish: __(
								'Publish this saved draft? This creates an immutable version visible to members.',
								'open-bulk-buying'
							),
							remove: __(
								'Remove this rule from the draft?',
								'open-bulk-buying'
							),
							discard: __(
								'Discard all unpublished edits and return to the current published rules?',
								'open-bulk-buying'
							),
						}[ confirm.action ]
					}
				</ConfirmDialog>
			) }
			{ discardDialog }
		</VStack>
	);
}

export default function RulesTab( { nav } ) {
	const { record, setRecord, error } = useRecord( PATH );
	const [ historyOpen, setHistoryOpen ] = useState( true );
	const panels = useMemo(
		() => ( {
			'rules-version': {
				size: 'wide',
				Panel: function VersionPanel( { id, onClose } ) {
					const version = record?.versions.find(
						( item ) => item.id === id
					);
					return (
						<SidePanel
							title={ sprintf(
								/* translators: %d: published version ID. */
								__( 'Rules version %d', 'open-bulk-buying' ),
								id
							) }
							onClose={ onClose }
							error={
								! version
									? {
											message: __(
												'This rules version is no longer available.',
												'open-bulk-buying'
											),
									  }
									: null
							}
						>
							{ version && (
								<PublishedRules version={ version } />
							) }
						</SidePanel>
					);
				},
			},
		} ),
		[ record ]
	);
	const stack = usePanelStack( {
		panels,
		guard: navigateHistory,
		base: historyOpen ? { type: 'rules-history', id: 0 } : null,
		onClose: () => {
			stack.reset();
			setHistoryOpen( false );
		},
	} );
	const showHistory = () => {
		stack.reset();
		setHistoryOpen( true );
	};
	const sidebar =
		record && historyOpen
			? stack.render(
					<VersionHistory
						versions={ record.versions }
						onClose={ () => setHistoryOpen( false ) }
					/>
			  )
			: null;
	let body = <Loading />;
	if ( error ) {
		body = <ErrorNotice error={ error } />;
	} else if ( record ) {
		body = (
			<RulesEditor
				record={ record }
				setRecord={ setRecord }
				onHistory={ showHistory }
			/>
		);
	}
	return (
		<SettingsPage
			nav={ nav }
			sidebar={ sidebar }
			sidebarSize={ stack.size || 'default' }
		>
			{ body }
		</SettingsPage>
	);
}
