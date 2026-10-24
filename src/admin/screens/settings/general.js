import {
	Button,
	SelectControl,
	TextControl,
	ToggleControl,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { createInterpolateElement, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { ErrorNotice, Loading } from '../../components';
import { useNotify, useRecord, useUnsavedChanges } from '../../hooks';
import { request } from '../../utils/api';
import { config } from '../../utils/config';
import { minorFactor, toMajor, toMinor } from '../../utils/format';
import Section from './section';
import SettingsPage from './settings-page';

function GeneralForm( { settings, captcha, onSaved } ) {
	const [ draft, setDraft ] = useState( {
		...settings,
		membership_fee_amount: String(
			toMajor( settings.membership_fee_amount )
		),
		membership_fee_order_interval: String(
			settings.membership_fee_order_interval
		),
	} );
	const [ isBusy, setIsBusy ] = useState( false );
	const [ error, setError ] = useState( null );
	// Write-only: the saved secret is never sent to the browser.
	const [ secret, setSecret ] = useState( '' );
	const notify = useNotify();
	const general = {
		...draft,
		membership_fee_amount: toMinor( draft.membership_fee_amount ),
		membership_fee_order_interval:
			draft.membership_fee_type === 'recurring'
				? Number( draft.membership_fee_order_interval )
				: settings.membership_fee_order_interval,
	};
	const isDirty =
		draft.allow_signups !== settings.allow_signups ||
		draft.allow_login_codes !== settings.allow_login_codes ||
		draft.captcha_provider !== settings.captcha_provider ||
		draft.turnstile_site_key !== settings.turnstile_site_key ||
		secret !== '' ||
		draft.require_member_approval !== settings.require_member_approval ||
		draft.membership_fee_type !== settings.membership_fee_type ||
		draft.membership_fee_amount !==
			String( toMajor( settings.membership_fee_amount ) ) ||
		draft.membership_fee_order_interval !==
			String( settings.membership_fee_order_interval );
	useUnsavedChanges( isDirty );

	const change = ( key ) => ( value ) =>
		setDraft( ( previous ) => ( { ...previous, [ key ]: value } ) );

	const save = async ( event ) => {
		event.preventDefault();
		setIsBusy( true );
		setError( null );
		try {
			onSaved(
				await request( '/settings', {
					method: 'POST',
					data: secret
						? { general, captcha_secret: secret }
						: { general },
				} )
			);
			notify.success(
				__( 'General settings saved.', 'open-bulk-buying' )
			);
		} catch ( err ) {
			setError( err );
		} finally {
			setIsBusy( false );
		}
	};

	return (
		<form onSubmit={ save }>
			<VStack spacing={ 6 } className="openbb-settings">
				<ErrorNotice
					error={ error }
					onDismiss={ () => setError( null ) }
				/>
				<Section title={ __( 'Signups', 'open-bulk-buying' ) }>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Allow signups', 'open-bulk-buying' ) }
						help={
							draft.allow_signups
								? __(
										'People can sign up from any page with the [openbb_signup] shortcode.',
										'open-bulk-buying'
								  )
								: __(
										'Signups are closed. The [openbb_signup] shortcode shows a "signups are closed" message.',
										'open-bulk-buying'
								  )
						}
						checked={ draft.allow_signups }
						onChange={ change( 'allow_signups' ) }
						disabled={ isBusy }
					/>
				</Section>
				<Section title={ __( 'Login', 'open-bulk-buying' ) }>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __(
							'Allow members to log in with an email code',
							'open-bulk-buying'
						) }
						help={
							draft.allow_login_codes
								? __(
										'Members can use a one-time email code instead of their password. Editors and administrators must still use their password. This is passwordless login, not two-factor authentication.',
										'open-bulk-buying'
								  )
								: __(
										'People log in with their password only.',
										'open-bulk-buying'
								  )
						}
						checked={ draft.allow_login_codes }
						onChange={ change( 'allow_login_codes' ) }
						disabled={ isBusy }
					/>
				</Section>
				<Section title={ __( 'Bot protection', 'open-bulk-buying' ) }>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Provider', 'open-bulk-buying' ) }
						help={
							draft.captcha_provider === 'turnstile'
								? __(
										'Signup and login forms ask Cloudflare Turnstile to check that visitors are human before sending email or checking a password.',
										'open-bulk-buying'
								  )
								: __(
										'Signup and login forms rely on rate limits and a hidden honeypot field only.',
										'open-bulk-buying'
								  )
						}
						value={ draft.captcha_provider }
						options={ [
							{
								label: __( 'None', 'open-bulk-buying' ),
								value: 'none',
							},
							{
								label: __(
									'Cloudflare Turnstile',
									'open-bulk-buying'
								),
								value: 'turnstile',
							},
						] }
						onChange={ change( 'captcha_provider' ) }
						disabled={ isBusy }
					/>
					{ draft.captcha_provider === 'turnstile' && (
						<>
							<p className="openbb-settings__note">
								{ createInterpolateElement(
									__(
										'Add this site in the <a>Cloudflare dashboard</a> under Turnstile to get a site key and secret key. Your site doesn’t need to use Cloudflare otherwise.',
										'open-bulk-buying'
									),
									{
										a: (
											// eslint-disable-next-line jsx-a11y/anchor-has-content
											<a
												href="https://dash.cloudflare.com/?to=/:account/turnstile"
												target="_blank"
												rel="noreferrer"
											/>
										),
									}
								) }
							</p>
							<TextControl
								__next40pxDefaultSize
								__nextHasNoMarginBottom
								label={ __( 'Site key', 'open-bulk-buying' ) }
								required
								value={ draft.turnstile_site_key }
								onChange={ change( 'turnstile_site_key' ) }
								disabled={ isBusy }
								autoComplete="off"
								spellCheck={ false }
							/>
							<TextControl
								__next40pxDefaultSize
								__nextHasNoMarginBottom
								label={ __( 'Secret key', 'open-bulk-buying' ) }
								help={
									captcha.secret_from_constant
										? __(
												'Set in wp-config.php with OPENBB_TURNSTILE_SECRET.',
												'open-bulk-buying'
										  )
										: captcha.secret_set &&
										  __(
												'A secret key is saved. Leave this empty to keep it.',
												'open-bulk-buying'
										  )
								}
								type="password"
								required={ ! captcha.secret_set }
								value={ secret }
								onChange={ setSecret }
								disabled={
									isBusy || captcha.secret_from_constant
								}
								autoComplete="new-password"
								spellCheck={ false }
							/>
						</>
					) }
				</Section>
				<Section title={ __( 'Member approval', 'open-bulk-buying' ) }>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __(
							'Require member approval',
							'open-bulk-buying'
						) }
						help={
							draft.require_member_approval
								? __(
										'After signup, new members remain Applicants until staff approve them.',
										'open-bulk-buying'
								  )
								: __(
										'After signup, new members are automatically approved as Members.',
										'open-bulk-buying'
								  )
						}
						checked={ draft.require_member_approval }
						onChange={ change( 'require_member_approval' ) }
						disabled={ isBusy }
					/>
				</Section>
				<Section title={ __( 'Membership fees', 'open-bulk-buying' ) }>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Fee frequency', 'open-bulk-buying' ) }
						value={ draft.membership_fee_type }
						options={ [
							{
								label: __( 'One time', 'open-bulk-buying' ),
								value: 'one_time',
							},
							{
								label: __( 'Recurring', 'open-bulk-buying' ),
								value: 'recurring',
							},
						] }
						onChange={ change( 'membership_fee_type' ) }
						disabled={ isBusy }
					/>
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ sprintf(
							/* translators: %s: ISO currency code. */
							__( 'Fee amount (%s)', 'open-bulk-buying' ),
							config.currency
						) }
						help={ __(
							'Set to 0 for no membership fee.',
							'open-bulk-buying'
						) }
						type="number"
						min="0"
						step={ 1 / minorFactor() }
						required
						value={ draft.membership_fee_amount }
						onChange={ change( 'membership_fee_amount' ) }
						disabled={ isBusy }
					/>
					{ draft.membership_fee_type === 'recurring' && (
						<TextControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							label={ __(
								'Charge every X orders',
								'open-bulk-buying'
							) }
							help={ __(
								'Number of orders between membership fees.',
								'open-bulk-buying'
							) }
							type="number"
							min="1"
							step="1"
							required
							value={ draft.membership_fee_order_interval }
							onChange={ change(
								'membership_fee_order_interval'
							) }
							disabled={ isBusy }
						/>
					) }
				</Section>
				<div>
					<Button
						__next40pxDefaultSize
						type="submit"
						variant="primary"
						isBusy={ isBusy }
						disabled={ isBusy || ! isDirty }
						accessibleWhenDisabled
					>
						{ __( 'Save settings', 'open-bulk-buying' ) }
					</Button>
				</div>
			</VStack>
		</form>
	);
}

export default function GeneralTab( { nav } ) {
	const { record: settings, setRecord, error } = useRecord( '/settings' );
	const [ saveCount, setSaveCount ] = useState( 0 );

	return (
		<SettingsPage nav={ nav }>
			{ error ? (
				<ErrorNotice error={ error } />
			) : ! settings ? (
				<Loading />
			) : (
				<GeneralForm
					key={ saveCount }
					settings={ settings.general }
					captcha={ settings.captcha }
					onSaved={ ( saved ) => {
						setRecord( saved );
						setSaveCount( ( count ) => count + 1 );
					} }
				/>
			) }
		</SettingsPage>
	);
}
