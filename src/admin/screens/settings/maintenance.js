import {
	Button,
	TextControl,
	ToggleControl,
	__experimentalText as Text,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { ErrorNotice, Loading } from '../../components';
import { useNotify, useRecord } from '../../hooks';
import { request } from '../../utils/api';
import { can } from '../../utils/config';
import Section from './section';
import SettingsPage from './settings-page';

// OPENBB_REST_Settings::DELETE_CONFIRMATION.
const DELETE_CONFIRMATION = 'DELETE ALL DATA';

function DangerZone( { onDeleted } ) {
	const [ confirmation, setConfirmation ] = useState( '' );
	const [ isBusy, setIsBusy ] = useState( false );
	const notify = useNotify();

	const deleteAll = async ( event ) => {
		event.preventDefault();
		setIsBusy( true );
		try {
			await request( '/settings/delete-all-data', {
				method: 'POST',
				data: { confirmation },
			} );
			setConfirmation( '' );
			notify.success(
				__(
					"This site's Open Bulk Buying data has been deleted.",
					'open-bulk-buying'
				)
			);
			onDeleted();
		} catch ( error ) {
			notify.error( error.message );
		} finally {
			setIsBusy( false );
		}
	};

	return (
		<Section title={ __( 'Danger zone', 'open-bulk-buying' ) } isDanger>
			<Text as="p">
				{ __(
					"Permanently deletes this site's Open Bulk Buying records, products and terms, co-op user metadata, and plugin settings. WordPress users and roles, other plugins' data, and other sites on this network are not changed. The database schema is retained.",
					'open-bulk-buying'
				) }
			</Text>
			<form onSubmit={ deleteAll }>
				<VStack spacing={ 3 } alignment="flex-start">
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ sprintf(
							/* translators: %s: the confirmation phrase. */
							__( 'Type %s to confirm', 'open-bulk-buying' ),
							DELETE_CONFIRMATION
						) }
						value={ confirmation }
						onChange={ setConfirmation }
						autoComplete="off"
					/>
					<Button
						__next40pxDefaultSize
						variant="primary"
						isDestructive
						type="submit"
						isBusy={ isBusy }
						disabled={
							isBusy || confirmation !== DELETE_CONFIRMATION
						}
						accessibleWhenDisabled
					>
						{ __(
							'Delete all Open Bulk Buying data',
							'open-bulk-buying'
						) }
					</Button>
				</VStack>
			</form>
		</Section>
	);
}

export default function MaintenanceTab( { nav } ) {
	const { record: settings, setRecord, error } = useRecord( '/settings' );
	const [ busy, setBusy ] = useState( null );
	const notify = useNotify();

	const run = async ( name, action, message ) => {
		setBusy( name );
		try {
			await action();
			notify.success( message );
		} catch ( err ) {
			notify.error( err.message );
		} finally {
			setBusy( null );
		}
	};

	const setTestMode = ( testMode ) =>
		run(
			'test_mode',
			async () =>
				setRecord(
					await request( '/settings', {
						method: 'POST',
						data: { test_mode: testMode },
					} )
				),
			testMode
				? __( 'Test mode enabled.', 'open-bulk-buying' )
				: __( 'Test mode disabled.', 'open-bulk-buying' )
		);

	const populateDemoData = () =>
		run(
			'demo',
			() => request( '/settings/demo-data', { method: 'POST' } ),
			__(
				'Demo data is ready. Populating again will not duplicate the demo records.',
				'open-bulk-buying'
			)
		);

	if ( error ) {
		return (
			<SettingsPage nav={ nav }>
				<ErrorNotice error={ error } />
			</SettingsPage>
		);
	}
	if ( ! settings ) {
		return (
			<SettingsPage nav={ nav }>
				<Loading />
			</SettingsPage>
		);
	}

	return (
		<SettingsPage nav={ nav }>
			<VStack spacing={ 6 } className="openbb-settings">
				<Section title={ __( 'Test mode', 'open-bulk-buying' ) }>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Enable test mode', 'open-bulk-buying' ) }
						help={ __(
							'Test mode enables a repeatable demo-data action. Demo records are marked and use the current site only.',
							'open-bulk-buying'
						) }
						checked={ !! settings.test_mode }
						disabled={ busy === 'test_mode' }
						onChange={ setTestMode }
					/>
				</Section>

				{ settings.test_mode && (
					<Section title={ __( 'Demo data', 'open-bulk-buying' ) }>
						<Text as="p">
							{ __(
								'Adds 41 marked demo products, 11 categories (including nested and empty categories), 4 suppliers, varied stock levels, a setup cycle and a draft order. Includes long names, varied units, container charges and inactive products. Repeating adds missing fixtures without duplicating stock. It does not create WordPress users or publish demo rules.',
								'open-bulk-buying'
							) }
						</Text>
						<div>
							<Button
								__next40pxDefaultSize
								variant="secondary"
								isBusy={ busy === 'demo' }
								disabled={ !! busy }
								accessibleWhenDisabled
								onClick={ populateDemoData }
							>
								{ __(
									'Populate demo data',
									'open-bulk-buying'
								) }
							</Button>
						</div>
					</Section>
				) }

				{ can( 'manage_options' ) && (
					<DangerZone
						// Deleting resets the plugin settings too.
						onDeleted={ () =>
							request( '/settings' ).then( setRecord, () => {} )
						}
					/>
				) }
			</VStack>
		</SettingsPage>
	);
}
