import { __ } from '@wordpress/i18n';

import { Link, useSearch } from '../../router';
import { config } from '../../utils/config';
import ProductsTab from './products-tab';
import TermsTab from './terms-tab';

const tabs = [
	{
		name: 'products',
		title: __( 'Products', 'open-bulk-buying' ),
		Component: ProductsTab,
	},
	{
		name: 'suppliers',
		title: __( 'Suppliers', 'open-bulk-buying' ),
		Component: ( props ) => <TermsTab taxonomy="suppliers" { ...props } />,
	},
	{
		name: 'categories',
		title: __( 'Categories', 'open-bulk-buying' ),
		Component: ( props ) => <TermsTab taxonomy="categories" { ...props } />,
	},
];

const tabUrl = ( name ) =>
	name === 'products'
		? config.urls.products
		: `${ config.urls.products }&tab=${ encodeURIComponent( name ) }`;

/**
 * Products, split into Products, Suppliers and Categories tabs. Tabs are router
 * links, so the URL stays bookmarkable and unsaved edits are guarded.
 */
export default function ProductsScreen() {
	const search = useSearch();
	const current = tabs.find( ( tab ) => tab.name === search.tab ) || tabs[ 0 ];

	const nav = (
		<nav
			className="openbb-tabs"
			aria-label={ __( 'Product sections', 'open-bulk-buying' ) }
		>
			<ul className="openbb-tabs__list">
				{ tabs.map( ( tab ) => {
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

	return <current.Component key={ current.name } nav={ nav } />;
}
