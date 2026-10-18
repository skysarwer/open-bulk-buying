import { __ } from '@wordpress/i18n';

import { Page } from '../../components';

/**
 * The Settings page frame for a tab: the title and the tab links, plus the
 * tab's own content and optional side panel.
 *
 * @param {Object}  props
 * @param {Element} props.nav         The tab links, from the Settings screen.
 * @param {Element} props.sidebar     Usually a SidePanel.
 * @param {string}  props.sidebarSize 'default' or 'wide'.
 * @param {Element} props.children
 */
export default function SettingsPage( {
	nav,
	sidebar,
	sidebarSize,
	children,
} ) {
	return (
		<Page
			title={ __( 'Settings', 'open-bulk-buying' ) }
			sidebar={ sidebar }
			sidebarSize={ sidebarSize }
		>
			{ nav }
			{ children }
		</Page>
	);
}
