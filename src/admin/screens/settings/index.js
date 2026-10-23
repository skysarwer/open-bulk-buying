import { __ } from '@wordpress/i18n';

import { Link, useSearch } from '../../router';
import { can, config } from '../../utils/config';
import MaintenanceTab from './maintenance';
import ProfileFieldsTab from './profile-fields';
import SettingsPage from './settings-page';
import RulesTab from './rules';
import RolesTab from './roles';
import GeneralTab from './general';

const tabs = [
	{
		name: 'general',
		title: __( 'General', 'open-bulk-buying' ),
		cap: 'openbb_manage_settings',
		Component: GeneralTab,
	},
	{
		name: 'rules',
		title: __( 'Co-op Rules', 'open-bulk-buying' ),
		cap: 'openbb_manage_rules',
		Component: RulesTab,
	},
	{
		name: 'profile-fields',
		title: __( 'Profile Fields', 'open-bulk-buying' ),
		cap: 'openbb_manage_profile_fields',
		Component: ProfileFieldsTab,
	},
	{
		name: 'roles',
		title: __( 'Team roles', 'open-bulk-buying' ),
		cap: 'openbb_manage_access',
		Component: RolesTab,
	},
	{
		name: 'maintenance',
		title: __( 'Data & Maintenance', 'open-bulk-buying' ),
		cap: 'openbb_manage_settings',
		Component: MaintenanceTab,
	},
];

const tabUrl = ( name ) =>
	`${ config.urls.settings }&tab=${ encodeURIComponent( name ) }`;

/**
 * Settings, split into tabs. Tabs are links, so switching tabs goes through the
 * router: the URL stays bookmarkable and unsaved edits are guarded as when leaving a screen.
 */
export default function SettingsScreen() {
	const search = useSearch();
	const visible = tabs.filter( ( tab ) => can( tab.cap ) );
	const current =
		visible.find( ( tab ) => tab.name === search.tab ) || visible[ 0 ];

	const nav = visible.length > 1 && (
		<nav
			className="openbb-tabs"
			aria-label={ __( 'Settings sections', 'open-bulk-buying' ) }
		>
			<ul className="openbb-tabs__list">
				{ visible.map( ( tab ) => {
					const isCurrent = tab === current;
					return (
						<li key={ tab.name }>
							<Link
								href={ tabUrl( tab.name ) }
								className={
									'openbb-tabs__item' +
									( isCurrent ? ' is-active' : '' )
								}
								aria-current={ isCurrent ? 'page' : undefined }
							>
								{ tab.title }
							</Link>
						</li>
					);
				} ) }
			</ul>
		</nav>
	);

	if ( ! current ) {
		return <SettingsPage />;
	}

	return <current.Component nav={ nav } />;
}
