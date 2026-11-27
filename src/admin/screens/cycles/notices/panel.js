import {
	Button,
	Notice,
	__experimentalHStack as HStack,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { ErrorNotice, SidePanel } from '../../../components';
import {
	discardUnsavedChanges,
	useNotify,
	useUnsavedChanges,
} from '../../../hooks';
import { request } from '../../../utils/api';
import SettingsForm from './settings-form';
import {
	STALE,
	closesSummary,
	fromSettings,
	noticeTitle,
	opensSummary,
	settingsProblems,
	toSettings,
} from './utils';

/**
 * Why a save failed. One refused because the notice changed in the meantime
 * offers to reload it, keeping the edits for saving again.
 *
 * @param {Object}   props
 * @param {Object}   props.error
 * @param {Function} props.onReload
 * @param {Function} props.onDismiss
 */
function SaveError( { error, onReload, onDismiss } ) {
	if ( error?.code !== STALE ) {
		return <ErrorNotice error={ error } onDismiss={ onDismiss } />;
	}

	return (
		<Notice
			status="error"
			isDismissible={ false }
			actions={ [
				{
					label: __( 'Reload notice', 'open-bulk-buying' ),
					onClick: onReload,
				},
			] }
		>
			{ error.message }{ ' ' }
			{ __(
				'Your edits here are kept: reload, then save again.',
				'open-bulk-buying'
			) }
		</Notice>
	);
}

/**
 * Where the notice stands, and the step that moves it on: publishing a draft
 * or unpublishing a published notice. The screen asks to confirm either.
 *
 * @param {Object}   props
 * @param {Object}   props.notice
 * @param {boolean}  props.isDirty  The settings have unsaved changes.
 * @param {Function} props.onAction ( action, notice ).
 */
function NoticeStatus( { notice, isDirty, onAction } ) {
	const isDraft = notice.status === 'draft';
	let hint;
	if ( isDirty ) {
		hint = isDraft
			? __(
					'Save or discard your changes before publishing.',
					'open-bulk-buying'
			  )
			: __(
					'Save or discard your changes before unpublishing.',
					'open-bulk-buying'
			  );
	} else if ( isDraft ) {
		hint = __(
			'Members can’t see a draft. Publish it when it is ready.',
			'open-bulk-buying'
		);
	} else {
		hint = `${
			notice.phase === 'scheduled'
				? opensSummary( notice )
				: closesSummary( notice )
		}.`;
	}

	return (
		<VStack spacing={ 2 } className="openbb-notice-status">
			<HStack justify="space-between" wrap>
				<span
					className={ `openbb-badge openbb-badge--notice-${ notice.phase }` }
				>
					{ notice.phase_label }
				</span>
				<Button
					__next40pxDefaultSize
					variant={ isDraft ? 'primary' : 'secondary' }
					disabled={ isDirty }
					accessibleWhenDisabled
					onClick={ () =>
						onAction( isDraft ? 'publish' : 'unpublish', notice )
					}
				>
					{ isDraft
						? __( 'Publish', 'open-bulk-buying' )
						: __( 'Unpublish', 'open-bulk-buying' ) }
				</Button>
			</HStack>
			<p className="openbb-muted">{ hint }</p>
		</VStack>
	);
}

/**
 * The side panel of one notice, or of a new one (`notice` null): its status
 * and settings, saved with the Save button.
 *
 * Every save carries the notice's revision; one based on an outdated copy is
 * refused and can be redone after reloading.
 *
 * @param {Object}      props
 * @param {number}      props.cycleId
 * @param {Object|null} props.notice   Null to create one.
 * @param {Function}    props.onSaved  Receives the saved notice.
 * @param {Function}    props.onAction ( action, notice ) to publish, unpublish or delete it.
 * @param {Function}    props.onClose
 */
export default function NoticePanel( {
	cycleId,
	notice,
	onSaved,
	onAction,
	onClose,
} ) {
	const saved = toSettings( notice );
	// Null until edited, so the form follows the notice when it changes elsewhere.
	const [ edits, setEdits ] = useState( null );
	const [ isBusy, setIsBusy ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ showProblems, setShowProblems ] = useState( false );
	const notify = useNotify();

	const draft = edits ?? saved;
	const isDirty =
		edits !== null && JSON.stringify( edits ) !== JSON.stringify( saved );
	useUnsavedChanges( isDirty );
	const problems = settingsProblems( draft );
	const path = notice ? `/cycles/${ cycleId }/notices/${ notice.id }` : null;

	const reload = async () => {
		try {
			onSaved( await request( path ) );
			setError( null );
		} catch ( err ) {
			setError( err );
		}
	};

	const save = async ( event ) => {
		event.preventDefault();
		if ( problems.length ) {
			setShowProblems( true );
			return;
		}
		setIsBusy( true );
		setError( null );
		try {
			const next = notice
				? await request( path, {
						method: 'PUT',
						data: {
							revision: notice.revision,
							...fromSettings( draft ),
						},
				  } )
				: await request( `/cycles/${ cycleId }/notices`, {
						method: 'POST',
						data: fromSettings( draft ),
				  } );
			setEdits( null );
			setShowProblems( false );
			notify.success(
				notice
					? __( 'Notice saved.', 'open-bulk-buying' )
					: __( 'Notice added as a draft.', 'open-bulk-buying' )
			);
			onSaved( next );
		} catch ( err ) {
			setError( err );
		} finally {
			setIsBusy( false );
		}
	};

	return (
		<SidePanel
			title={
				notice
					? noticeTitle( notice )
					: __( 'New notice', 'open-bulk-buying' )
			}
			onClose={ onClose }
		>
			<form onSubmit={ save }>
				<VStack spacing={ 4 }>
					{ notice ? (
						<NoticeStatus
							notice={ notice }
							isDirty={ isDirty }
							onAction={ onAction }
						/>
					) : (
						<p className="openbb-muted">
							{ __(
								'A new notice is saved as a draft. Publish it once it is ready.',
								'open-bulk-buying'
							) }
						</p>
					) }
					<SaveError
						error={ error }
						onReload={ reload }
						onDismiss={ () => setError( null ) }
					/>
					{ showProblems && problems.length > 0 && (
						<Notice status="warning" isDismissible={ false }>
							<ul className="openbb-problems">
								{ problems.map( ( problem ) => (
									<li key={ problem }>{ problem }</li>
								) ) }
							</ul>
						</Notice>
					) }
					<SettingsForm
						draft={ draft }
						onChange={ ( changes ) =>
							setEdits( ( prev ) => ( {
								...( prev ?? saved ),
								...changes,
							} ) )
						}
					/>
					<HStack
						justify={ notice ? 'space-between' : 'flex-end' }
						className="openbb-form-actions"
					>
						{ notice && (
							<Button
								__next40pxDefaultSize
								variant="tertiary"
								isDestructive
								disabled={ isBusy }
								onClick={ () => onAction( 'delete', notice ) }
							>
								{ __( 'Delete', 'open-bulk-buying' ) }
							</Button>
						) }
						<HStack justify="flex-end" expanded={ ! notice }>
							{ isDirty && (
								<Button
									__next40pxDefaultSize
									variant="tertiary"
									disabled={ isBusy }
									onClick={ () => {
										discardUnsavedChanges();
										setEdits( null );
										setShowProblems( false );
									} }
								>
									{ __(
										'Discard changes',
										'open-bulk-buying'
									) }
								</Button>
							) }
							<Button
								__next40pxDefaultSize
								variant="primary"
								type="submit"
								isBusy={ isBusy }
								disabled={ isBusy || ! isDirty }
								accessibleWhenDisabled
							>
								{ notice
									? __( 'Save', 'open-bulk-buying' )
									: __( 'Add notice', 'open-bulk-buying' ) }
							</Button>
						</HStack>
					</HStack>
				</VStack>
			</form>
		</SidePanel>
	);
}
