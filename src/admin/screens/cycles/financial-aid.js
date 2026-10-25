import {
	Button,
	Modal,
	Notice,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { DataViews } from '@wordpress/dataviews/wp';

import { ConfirmBody, ErrorNotice, SidePanel } from '../../components';
import { useList, useNotify } from '../../hooks';
import { request } from '../../utils/api';

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
								'This records eligibility only. No amount is allocated and the order total is unchanged.',
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
								'Set the aid request for %1$s to %2$s? This records eligibility only; it does not allocate an amount or change the order total.',
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

export default function FinancialAid( { cycle, children } ) {
	const [ view, setView ] = useState( initialView );
	const [ selected, setSelected ] = useState( null );
	const list = useList( `/cycles/${ cycle.id }/aid`, view, mapping );
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
				<Notice status="info" isDismissible={ false }>
					{ __(
						'Approve or decline eligibility here. Aid amounts and percentage-based allocation will be calculated separately; decisions do not change order totals. Draft requests are not shown. Paid, fulfilled, cancelled, allocated, and completed-cycle requests are read-only. You cannot decide your own request.',
						'open-bulk-buying'
					) }
				</Notice>
				<Button
					variant="secondary"
					onClick={ () => {
						setSelected( null );
						list.reload();
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
					notify.success(
						__( 'Aid decision saved.', 'open-bulk-buying' )
					);
				} }
			/>
		),
	} );
}
