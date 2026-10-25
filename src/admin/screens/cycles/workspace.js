import { Notice } from '@wordpress/components';
import { Fragment } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { ErrorNotice, Loading, Page } from '../../components';
import { useNotify, useRecord } from '../../hooks';
import { Link, navigate, useSearch } from '../../router';
import { can, config } from '../../utils/config';
import { CycleDetails, CycleSummary } from './list';
import FinancialAid from './financial-aid';

const sections = [
	{ name: 'details', title: __( 'Details', 'open-bulk-buying' ) },
	{
		name: 'financial-aid',
		title: __( 'Financial Aid', 'open-bulk-buying' ),
		cap: 'openbb_view_aid',
	},
];

export default function CycleWorkspace( { cycleId } ) {
	const validId = /^[1-9]\d*$/.test( cycleId );
	const {
		record: cycle,
		setRecord,
		error,
		isLoading,
	} = useRecord( validId ? `/cycles/${ cycleId }` : null );
	const search = useSearch();
	const notify = useNotify();
	const visible = sections.filter(
		( section ) => ! section.cap || can( section.cap )
	);
	const current =
		visible.find( ( section ) => section.name === search.tab ) ||
		visible.find(
			( section ) =>
				section.name ===
				( can( 'openbb_manage_cycles' ) ? 'details' : 'financial-aid' )
		) ||
		visible[ 0 ];

	const renderPage = ( aid = null ) => (
		<Page
			sidebar={ aid?.sidebar }
			title={ cycle?.name || __( 'Manage cycle', 'open-bulk-buying' ) }
			actions={
				cycle && (
					<span
						className={ `openbb-badge openbb-badge--cycle-${ cycle.status }` }
					>
						{ cycle.status_label }
					</span>
				)
			}
		>
			<div className="openbb-cycle-workspace__back">
				<Link href={ config.urls.cycles }>
					{ __( 'Back to cycles', 'open-bulk-buying' ) }
				</Link>
			</div>
			{ ! validId && (
				<ErrorNotice
					error={ __( 'Invalid cycle ID.', 'open-bulk-buying' ) }
				/>
			) }
			<ErrorNotice error={ error } />
			{ isLoading && <Loading /> }
			{ cycle && (
				<>
					<nav
						className="openbb-tabs"
						aria-label={ __(
							'Cycle sections',
							'open-bulk-buying'
						) }
					>
						<ul className="openbb-tabs__list">
							{ visible.map( ( section ) => (
								<li key={ section.name }>
									<Link
										href={ `${ config.urls.cycles }&cycle=${ cycle.id }&tab=${ section.name }` }
										className={ `openbb-tabs__item${
											current === section
												? ' is-active'
												: ''
										}` }
										aria-current={
											current === section
												? 'page'
												: undefined
										}
									>
										{ section.title }
									</Link>
								</li>
							) ) }
						</ul>
					</nav>
					{ current.name === 'financial-aid' ? (
						aid?.content
					) : (
						<div className="openbb-cycle-workspace__details">
							<h3>
								{ __( 'Cycle details', 'open-bulk-buying' ) }
							</h3>
							{ can( 'openbb_manage_cycles' ) ? (
								<CycleDetails
									cycle={ cycle }
									onCancel={ () =>
										navigate( config.urls.cycles )
									}
									onSaved={ ( saved ) => {
										setRecord( saved );
										notify.success(
											__(
												'Cycle saved.',
												'open-bulk-buying'
											)
										);
									} }
								/>
							) : (
								<>
									<Notice
										status="info"
										isDismissible={ false }
									>
										{ __(
											'You can review this cycle, but do not have permission to edit its details.',
											'open-bulk-buying'
										) }
									</Notice>
									<dl className="openbb-details">
										{ [
											[
												'opens_at',
												__(
													'Ordering opens',
													'open-bulk-buying'
												),
											],
											[
												'closes_at',
												__(
													'Ordering closes',
													'open-bulk-buying'
												),
											],
											[
												'pickup_opens_at',
												__(
													'Pickup starts',
													'open-bulk-buying'
												),
											],
											[
												'pickup_closes_at',
												__(
													'Pickup ends',
													'open-bulk-buying'
												),
											],
											[
												'pickup_location',
												__(
													'Pickup location',
													'open-bulk-buying'
												),
											],
										].map( ( [ field, label ] ) => (
											<Fragment key={ field }>
												<dt>{ label }</dt>
												<dd>
													{ cycle[ field ] ||
														__(
															'Not set',
															'open-bulk-buying'
														) }
												</dd>
											</Fragment>
										) ) }
									</dl>
									<CycleSummary cycle={ cycle } />
								</>
							) }
						</div>
					) }
				</>
			) }
		</Page>
	);

	return cycle && current.name === 'financial-aid' ? (
		<FinancialAid cycle={ cycle }>{ renderPage }</FinancialAid>
	) : (
		renderPage()
	);
}
