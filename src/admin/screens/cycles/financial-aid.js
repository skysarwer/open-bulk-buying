import {
	Button,
	Modal,
	Notice,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { DataViews } from '@wordpress/dataviews/wp';

import { ConfirmBody, ErrorNotice, SidePanel } from '../../components';
import { useList, useNotify } from '../../hooks';
import { request } from '../../utils/api';
import { formatMoney } from '../../utils/format';

const statuses = [
	{ value: 'pending', label: __( 'Pending', 'open-bulk-buying' ) },
	{ value: 'approved', label: __( 'Approved', 'open-bulk-buying' ) },
	{ value: 'declined', label: __( 'Declined', 'open-bulk-buying' ) },
];
const fields = [
	{
		id: 'id',
		label: __( 'Order', 'open-bulk-buying' ),
		type: 'integer',
		filterBy: false,
	},
	{
		id: 'member_name',
		label: __( 'Member', 'open-bulk-buying' ),
		type: 'text',
		enableSorting: false,
		filterBy: false,
	},
	{
		id: 'aid_status',
		label: __( 'Aid status', 'open-bulk-buying' ),
		type: 'text',
		elements: statuses,
		render: ( { item } ) => item.aid_status_label,
		enableSorting: false,
		filterBy: { operators: [ 'isAny' ], isPrimary: true },
	},
	{
		id: 'aid_reason',
		label: __( 'Request reason', 'open-bulk-buying' ),
		type: 'text',
		enableSorting: false,
		filterBy: false,
	},
	{
		id: 'status_label',
		label: __( 'Order status', 'open-bulk-buying' ),
		type: 'text',
		enableSorting: false,
		filterBy: false,
	},
	{
		id: 'aid_decided_at',
		label: __( 'Decision date', 'open-bulk-buying' ),
		type: 'datetime',
		enableSorting: false,
		filterBy: false,
	},
];
const mapping = {
	orderby: { id: 'id' },
	filters: { aid_status: ( filter ) => ( { aid_status: filter.value } ) },
};
const initialView = {
	type: 'list',
	page: 1,
	perPage: 20,
	search: '',
	sort: { field: 'id', direction: 'desc' },
	fields: [ 'aid_status', 'status_label' ],
	titleField: 'member_name',
	filters: [],
};

function AidRequestPanel( { cycle, item, onClose, onSaved } ) {
	const [ decision, setDecision ] = useState( null );

	return (
		<SidePanel title={ item.member_name } onClose={ onClose }>
			<VStack spacing={ 4 }>
				<dl className="openbb-details">
					<dt>{ __( 'Order', 'open-bulk-buying' ) }</dt>
					<dd>{ item.id }</dd>
					<dt>{ __( 'Order status', 'open-bulk-buying' ) }</dt>
					<dd>{ item.status_label }</dd>
					<dt>{ __( 'Aid status', 'open-bulk-buying' ) }</dt>
					<dd>{ item.aid_status_label }</dd>
					<dt>{ __( 'Aid allocated', 'open-bulk-buying' ) }</dt>
					<dd>{ formatMoney( item.aid_approved || 0 ) }</dd>
					<dt>{ __( 'Decision date', 'open-bulk-buying' ) }</dt>
					<dd>
						{ item.aid_decided_at ||
							__( 'Not decided', 'open-bulk-buying' ) }
					</dd>
				</dl>
				<div>
					<h3>{ __( 'Request reason', 'open-bulk-buying' ) }</h3>
					<p className="openbb-aid-reason">
						{ item.aid_reason ||
							__( 'No reason provided.', 'open-bulk-buying' ) }
					</p>
				</div>
				{ item.can_decide ? (
					<>
						<p>
							{ __(
								'This records eligibility only. Use "Distribute aid" to allocate amounts to approved orders.',
								'open-bulk-buying'
							) }
						</p>
						{ statuses
							.filter( ( status ) => status.value !== 'pending' )
							.map( ( status ) => (
								<Button
									key={ status.value }
									__next40pxDefaultSize
									variant={
										status.value === 'approved'
											? 'primary'
											: 'secondary'
									}
									disabled={
										item.aid_status === status.value
									}
									onClick={ () => setDecision( status ) }
								>
									{ status.value === 'approved'
										? __( 'Approve', 'open-bulk-buying' )
										: __( 'Decline', 'open-bulk-buying' ) }
								</Button>
							) ) }
					</>
				) : (
					<Notice status="info" isDismissible={ false }>
						{ __(
							'This request is read-only. Decisions require permission to decide aid for another member, an unpaid submitted order, an unallocated request, and a cycle that is not complete.',
							'open-bulk-buying'
						) }
					</Notice>
				) }
			</VStack>
			{ decision && (
				<Modal
					title={
						decision.value === 'approved'
							? __( 'Approve aid request', 'open-bulk-buying' )
							: __( 'Decline aid request', 'open-bulk-buying' )
					}
					onRequestClose={ () => setDecision( null ) }
					size="small"
				>
					<ConfirmBody
						message={ sprintf(
							/* translators: 1: member name, 2: eligibility decision. */
							__(
								'Set the aid request for %1$s to %2$s? This records eligibility only; amounts are allocated with "Distribute aid".',
								'open-bulk-buying'
							),
							item.member_name,
							decision.label
						) }
						confirmLabel={ decision.label }
						closeModal={ () => setDecision( null ) }
						onConfirm={ async () => {
							const saved = await request(
								`/cycles/${ cycle.id }/aid/${ item.id }`,
								{
									method: 'POST',
									data: {
										decision: decision.value,
										expected_revision: item.revision,
									},
								}
							);
							onSaved( saved );
						} }
					/>
				</Modal>
			) }
		</SidePanel>
	);
}

function useAidSummary( cycleId ) {
	const [ summary, setSummary ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ version, setVersion ] = useState( 0 );

	useEffect( () => {
		let current = true;
		setError( null );
		request( `/cycles/${ cycleId }/aid/summary` )
			.then( ( data ) => current && setSummary( data ) )
			.catch( ( err ) => current && setError( err ) );
		return () => {
			current = false;
		};
	}, [ cycleId, version ] );

	return { summary, error, reload: () => setVersion( ( v ) => v + 1 ) };
}

function AidFunds( { summary } ) {
	if ( ! summary ) {
		return null;
	}
	const { requests } = summary;
	return (
		<dl className="openbb-details openbb-details--inline">
			<dt>{ __( 'Donated this cycle', 'open-bulk-buying' ) }</dt>
			<dd>
				<strong>{ formatMoney( summary.donated ) }</strong>{ ' ' }
				{ sprintf(
					/* translators: %d: number of orders with a donation. */
					_n(
						'(%d order)',
						'(%d orders)',
						summary.donors,
						'open-bulk-buying'
					),
					summary.donors
				) }
			</dd>
			<dt>{ __( 'Donations received', 'open-bulk-buying' ) }</dt>
			<dd>{ formatMoney( summary.received ) }</dd>
			<dt>{ __( 'Aid allocated', 'open-bulk-buying' ) }</dt>
			<dd>{ formatMoney( summary.allocated ) }</dd>
			<dt>{ __( 'Available for aid', 'open-bulk-buying' ) }</dt>
			<dd>
				<strong>{ formatMoney( summary.available ) }</strong>
			</dd>
			<dt>{ __( 'Requests', 'open-bulk-buying' ) }</dt>
			<dd>
				{ sprintf(
					/* translators: 1: pending, 2: approved, 3: declined request counts. */
					__(
						'%1$d pending, %2$d approved, %3$d declined',
						'open-bulk-buying'
					),
					requests.pending,
					requests.approved,
					requests.declined
				) }
			</dd>
		</dl>
	);
}

function AidDistribution( { cycle, summary, onDistributed } ) {
	const [ isConfirming, setIsConfirming ] = useState( false );
	const plan = summary?.distribution;
	if ( ! plan ) {
		return null;
	}
	return (
		<div className="openbb-aid-distribution">
			<h4>{ __( 'Equal distribution', 'open-bulk-buying' ) }</h4>
			<p>
				{ plan.recipients > 0
					? sprintf(
							/* translators: 1: percentage, 2: number of orders, 3: amount distributed, 4: eligible goods total. */
							__(
								'Each approved, unpaid order gets %1$s%% off its goods (%2$d orders, %3$s of %4$s). Membership fees and donations are never covered.',
								'open-bulk-buying'
							),
							plan.percent,
							plan.recipients,
							formatMoney( plan.allocated ),
							formatMoney( plan.eligible )
					  )
					: __(
							'No approved, unpaid orders to distribute aid to.',
							'open-bulk-buying'
					  ) }
			</p>
			{ plan.can_distribute && (
				<Button
					variant="primary"
					disabled={ plan.recipients === 0 }
					onClick={ () => setIsConfirming( true ) }
				>
					{ __( 'Distribute aid', 'open-bulk-buying' ) }
				</Button>
			) }
			{ isConfirming && (
				<Modal
					title={ __( 'Distribute aid', 'open-bulk-buying' ) }
					onRequestClose={ () => setIsConfirming( false ) }
					size="small"
				>
					<ConfirmBody
						message={ sprintf(
							/* translators: 1: percentage, 2: number of orders. */
							__(
								'Apply a %1$s%% discount to the goods on %2$d approved, unpaid orders? Their totals will change. You can run this again if donations or approvals change; paid and fulfilled orders keep the aid they already received.',
								'open-bulk-buying'
							),
							plan.percent,
							plan.recipients
						) }
						confirmLabel={ __( 'Distribute', 'open-bulk-buying' ) }
						closeModal={ () => setIsConfirming( false ) }
						onConfirm={ async () => {
							const saved = await request(
								`/cycles/${ cycle.id }/aid/distribute`,
								{ method: 'POST', data: { confirmed: true } }
							);
							onDistributed( saved );
						} }
					/>
				</Modal>
			) }
		</div>
	);
}

export default function FinancialAid( { cycle, children } ) {
	const [ view, setView ] = useState( initialView );
	const [ selected, setSelected ] = useState( null );
	const list = useList( `/cycles/${ cycle.id }/aid`, view, mapping );
	const funds = useAidSummary( cycle.id );
	const notify = useNotify();
	const actions = [
		{
			id: 'view',
			label: __( 'View request', 'open-bulk-buying' ),
			isPrimary: true,
			callback: ( [ item ] ) => setSelected( item ),
		},
	];

	const content = (
		<section aria-label={ __( 'Financial Aid', 'open-bulk-buying' ) }>
			<div className="openbb-cycle-workspace__intro">
				<h3>{ __( 'Financial Aid', 'open-bulk-buying' ) }</h3>
				<AidFunds summary={ funds.summary } />
				<p className="description">
					{ __(
						'Funds come from donations on this cycle\'s submitted orders (drafts and cancelled orders excluded). "Received" counts only paid orders.',
						'open-bulk-buying'
					) }
				</p>
				<ErrorNotice error={ funds.error } />
				<AidDistribution
					cycle={ cycle }
					summary={ funds.summary }
					onDistributed={ () => {
						setSelected( null );
						list.reload();
						funds.reload();
						notify.success(
							__( 'Aid distributed.', 'open-bulk-buying' )
						);
					} }
				/>
				<Notice status="info" isDismissible={ false }>
					{ __(
						'Approve or decline eligibility here, then distribute: the pool is split so every approved, unpaid order gets the same percentage off its goods, up to 100%. Draft requests are not shown. Paid, fulfilled, cancelled, allocated, and completed-cycle requests are read-only. You cannot decide your own request.',
						'open-bulk-buying'
					) }
				</Notice>
				<Button
					variant="secondary"
					onClick={ () => {
						setSelected( null );
						list.reload();
						funds.reload();
					} }
					disabled={ list.isLoading }
				>
					{ __( 'Refresh requests', 'open-bulk-buying' ) }
				</Button>
			</div>
			<ErrorNotice error={ list.error } />
			<DataViews
				data={ list.items }
				fields={ fields }
				view={ view }
				onChangeView={ setView }
				actions={ actions }
				paginationInfo={ list.paginationInfo }
				isLoading={ list.isLoading }
				defaultLayouts={ { list: {}, table: {} } }
				getItemId={ ( item ) => String( item.id ) }
				selection={ selected ? [ String( selected.id ) ] : [] }
				onChangeSelection={ ( ids ) => {
					const item = list.items.find(
						( entry ) => String( entry.id ) === ids[ 0 ]
					);
					if ( item ) {
						setSelected( item );
					}
				} }
				onClickItem={ setSelected }
				isItemClickable={ () => true }
				searchLabel={ __(
					'Search members or order IDs',
					'open-bulk-buying'
				) }
			/>
		</section>
	);

	return children( {
		content,
		sidebar: selected && (
			<AidRequestPanel
				key={ selected.id }
				cycle={ cycle }
				item={ selected }
				onClose={ () => setSelected( null ) }
				onSaved={ ( saved ) => {
					setSelected( saved );
					list.reload();
					funds.reload();
					notify.success(
						__( 'Aid decision saved.', 'open-bulk-buying' )
					);
				} }
			/>
		),
	} );
}
