import {
	Button,
	Modal,
	RadioControl,
	__experimentalHStack as HStack,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import { ErrorNotice } from '../../components';
import { requestList } from '../../utils/api';
import { openOrderSlips, SLIP_STATUSES } from '../../utils/documents';

const MAX_SLIPS = 500;

/**
 * Chooses which of the cycle's orders to print slips for.
 *
 * @param {Object}   props
 * @param {Object}   props.cycle
 * @param {Object}   props.view    The orders list view, for its search and status filter.
 * @param {Function} props.onClose
 */
export default function PrintSlipsModal( { cycle, view, onClose } ) {
	const statusFilter = view.filters?.find(
		( filter ) => filter.field === 'status'
	)?.value;
	const hasListFilters = !! view.search || !! statusFilter?.length;
	const [ scope, setScope ] = useState( 'all' );
	const [ paid, setPaid ] = useState( 'all' );
	const [ count, setCount ] = useState( null );
	const [ error, setError ] = useState( null );

	const filtered = scope === 'filtered';
	const statuses =
		filtered && statusFilter?.length
			? SLIP_STATUSES.filter( ( status ) =>
					statusFilter.includes( status )
			  )
			: SLIP_STATUSES;
	const search = filtered ? view.search || '' : '';
	const statusKey = statuses.join( ',' );

	useEffect( () => {
		if ( ! statusKey ) {
			setCount( 0 );
			return;
		}
		let current = true;
		setCount( null );
		setError( null );
		const query = {
			type: 'cycle',
			cycle_id: cycle.id,
			status: statusKey.split( ',' ),
			per_page: 1,
		};
		if ( paid !== 'all' ) {
			query.paid = paid;
		}
		if ( search ) {
			query.search = search;
		}
		requestList( '/orders', query )
			.then( ( page ) => current && setCount( page.totalItems ) )
			.catch( ( err ) => current && setError( err ) );
		return () => {
			current = false;
		};
	}, [ cycle.id, statusKey, paid, search ] );

	let summary = __( 'Counting orders…', 'open-bulk-buying' );
	if ( count !== null ) {
		summary = sprintf(
			/* translators: %d: number of order slips. */
			_n(
				'%d order slip will be printed.',
				'%d order slips will be printed.',
				count,
				'open-bulk-buying'
			),
			count
		);
	}
	const tooMany = count !== null && count > MAX_SLIPS;

	return (
		<Modal
			title={ __( 'Print order slips', 'open-bulk-buying' ) }
			onRequestClose={ onClose }
			size="medium"
		>
			<VStack spacing={ 4 }>
				<p>
					{ __(
						'Slips are sorted by last name and printed one after another with a line to cut along, each with a box to tick as products are packed. Slips are printed for submitted, prepaid and fulfilled orders.',
						'open-bulk-buying'
					) }
				</p>
				{ hasListFilters && (
					<RadioControl
						label={ __( 'Orders', 'open-bulk-buying' ) }
						selected={ scope }
						options={ [
							{
								value: 'all',
								label: __(
									'All orders in this cycle',
									'open-bulk-buying'
								),
							},
							{
								value: 'filtered',
								label: __(
									'Only orders matching the current search and filters',
									'open-bulk-buying'
								),
							},
						] }
						onChange={ setScope }
					/>
				) }
				<RadioControl
					label={ __( 'Payment', 'open-bulk-buying' ) }
					selected={ paid }
					options={ [
						{
							value: 'all',
							label: __( 'Paid and unpaid', 'open-bulk-buying' ),
						},
						{
							value: 'unpaid',
							label: __( 'Unpaid only', 'open-bulk-buying' ),
						},
						{
							value: 'paid',
							label: __( 'Paid only', 'open-bulk-buying' ),
						},
					] }
					onChange={ setPaid }
				/>
				<ErrorNotice error={ error } />
				<p aria-live="polite">
					<strong>{ summary }</strong>
				</p>
				{ tooMany && (
					<p>
						{ sprintf(
							/* translators: %d: maximum number of order slips. */
							__(
								'Print at most %d order slips at a time. Narrow the list and try again.',
								'open-bulk-buying'
							),
							MAX_SLIPS
						) }
					</p>
				) }
				<HStack justify="flex-end">
					<Button variant="tertiary" onClick={ onClose }>
						{ __( 'Cancel', 'open-bulk-buying' ) }
					</Button>
					<Button
						variant="primary"
						disabled={ ! count || tooMany }
						onClick={ () => {
							openOrderSlips( {
								cycleId: cycle.id,
								statuses,
								paid,
								search,
							} );
							onClose();
						} }
					>
						{ __( 'Open printable slips', 'open-bulk-buying' ) }
					</Button>
				</HStack>
			</VStack>
		</Modal>
	);
}
