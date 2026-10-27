import {
	Button,
	CheckboxControl,
	Notice,
	TextControl,
	__experimentalConfirmDialog as ConfirmDialog,
	__experimentalHStack as HStack,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { ErrorNotice, RecordLink } from '../../components';
import { request } from '../../utils/api';
import { can, config } from '../../utils/config';
import { formatDate } from './fields';

const STATUS_LABELS = {
	not_applicable: __( 'No fee applies', 'open-bulk-buying' ),
	due: __( 'Due', 'open-bulk-buying' ),
	pending: __( 'Payment claimed, waiting for verification', 'open-bulk-buying' ),
	paid: __( 'Paid', 'open-bulk-buying' ),
};

function FeeHistory( { history } ) {
	if ( ! history.length ) {
		return null;
	}
	return (
		<table className="widefat striped openbb-compact-table">
			<thead>
				<tr>
					<th>{ __( 'Order', 'open-bulk-buying' ) }</th>
					<th>{ __( 'Status', 'open-bulk-buying' ) }</th>
					<th>{ __( 'Amount', 'open-bulk-buying' ) }</th>
					<th>{ __( 'Paid', 'open-bulk-buying' ) }</th>
					<th>{ __( 'Reference', 'open-bulk-buying' ) }</th>
				</tr>
			</thead>
			<tbody>
				{ history.map( ( row ) => (
					<tr key={ row.id }>
						<td>
							{ can( 'openbb_view_orders' ) ? (
								<RecordLink
									type="order"
									id={ row.id }
									href={ `${ config.urls.orders }&order=${ row.id }` }
								>
									#{ row.id }
								</RecordLink>
							) : (
								`#${ row.id }`
							) }
						</td>
						<td>{ row.status_label || row.status }</td>
						<td>{ row.total_formatted }</td>
						<td>{ row.paid_at ? formatDate( row.paid_at ) : '—' }</td>
						<td>{ row.reference || '—' }</td>
					</tr>
				) ) }
			</tbody>
		</table>
	);
}

/**
 * The member's membership fee: its status and history and, for staff who may record
 * payments, recording a payment or reviewing a claimed one.
 *
 * Payments are only ever added or reviewed here, never toggled back to unpaid.
 *
 * @param {Object}   props
 * @param {Object}   props.member
 * @param {Function} props.onSaved Called with the refreshed member and a success message.
 */
export default function MembershipFee( { member, onSaved } ) {
	const fee = member.membership_fee;
	const [ reference, setReference ] = useState( '' );
	const [ confirmClaim, setConfirmClaim ] = useState( false );
	const [ busy, setBusy ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ confirmReject, setConfirmReject ] = useState( false );

	if ( ! fee ) {
		return null;
	}

	const history = fee.history || [];
	if ( fee.status === 'not_applicable' && ! history.length ) {
		return null;
	}

	const canRecord = !! member.can_record_membership_payment;
	const isApplicant = member.status === 'applicant';
	const canApprove = ( member.status_options || [] ).includes( 'active' );
	const pendingId = fee.pending_order_id;
	const isPending = fee.status === 'pending' && pendingId > 0;
	const amount = fee.amount ? fee.amount_formatted : '';

	const send = async ( action, path, options, message ) => {
		setBusy( action );
		setError( null );
		try {
			const saved = await request( path, options );
			setReference( '' );
			setConfirmClaim( false );
			onSaved( saved, message );
		} catch ( err ) {
			setError( err );
		}
		setBusy( null );
	};

	const pay = ( action, data, message ) =>
		send(
			action,
			`/members/${ member.id }/membership-payment`,
			{ method: 'POST', data },
			message
		);

	const approve = () =>
		confirmClaim
			? pay(
					'approve',
					{ order_id: pendingId, approve_member: true },
					__( 'Membership approved and payment confirmed.', 'open-bulk-buying' )
			  )
			: // Approve only; the claimed payment stays waiting for verification.
			  send(
					'approve',
					`/members/${ member.id }`,
					{ method: 'PUT', data: { status: 'active' } },
					__( 'Membership approved. The claimed payment is still waiting for verification.', 'open-bulk-buying' )
			  );

	return (
		<section className="openbb-membership-fee">
			<h3>{ __( 'Membership fee', 'open-bulk-buying' ) }</h3>
			<VStack spacing={ 3 }>
				<ErrorNotice error={ error } onDismiss={ () => setError( null ) } />
				<dl className="openbb-details">
					<dt>{ __( 'Status', 'open-bulk-buying' ) }</dt>
					<dd>{ STATUS_LABELS[ fee.status ] || fee.status }</dd>
					{ !! amount && fee.status !== 'not_applicable' && (
						<>
							<dt>{ __( 'Amount', 'open-bulk-buying' ) }</dt>
							<dd>{ amount }</dd>
						</>
					) }
				</dl>

				{ isPending && (
					<Notice status="warning" isDismissible={ false }>
						{ sprintf(
							/* translators: %d: order number. */
							__( 'This person says they have paid (order #%d). Check that the payment arrived before confirming it.', 'open-bulk-buying' ),
							pendingId
						) }
					</Notice>
				) }

				{ canRecord && isPending && isApplicant && canApprove && (
					<VStack spacing={ 2 }>
						<CheckboxControl
							__nextHasNoMarginBottom
							label={ __( 'Confirm claimed membership payment', 'open-bulk-buying' ) }
							help={ __( 'Approves the membership and marks the fee paid in one step. Leave unchecked to approve the membership only; the claim then stays waiting for verification.', 'open-bulk-buying' ) }
							checked={ confirmClaim }
							disabled={ !! busy }
							onChange={ setConfirmClaim }
						/>
						<HStack justify="flex-start">
							<Button
								__next40pxDefaultSize
								variant="primary"
								isBusy={ busy === 'approve' }
								disabled={ !! busy }
								accessibleWhenDisabled
								onClick={ approve }
							>
								{ confirmClaim
									? __( 'Approve membership and confirm payment', 'open-bulk-buying' )
									: __( 'Approve membership', 'open-bulk-buying' ) }
							</Button>
						</HStack>
					</VStack>
				) }

				{ canRecord && isPending && (
					<HStack justify="flex-start">
						{ ! ( isApplicant && canApprove ) && (
							<Button
								__next40pxDefaultSize
								variant="primary"
								isBusy={ busy === 'confirm' }
								disabled={ !! busy }
								accessibleWhenDisabled
								onClick={ () =>
									pay(
										'confirm',
										{ order_id: pendingId },
										__( 'Membership payment confirmed.', 'open-bulk-buying' )
									)
								}
							>
								{ __( 'Confirm payment', 'open-bulk-buying' ) }
							</Button>
						) }
						<Button
							__next40pxDefaultSize
							variant="secondary"
							isDestructive
							isBusy={ busy === 'reject' }
							disabled={ !! busy }
							accessibleWhenDisabled
							onClick={ () => setConfirmReject( true ) }
						>
							{ __( 'Reject claimed payment', 'open-bulk-buying' ) }
						</Button>
					</HStack>
				) }

				{ canRecord && fee.status === 'due' && (
					<VStack spacing={ 2 }>
						<TextControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							label={ __( 'Payment reference (optional)', 'open-bulk-buying' ) }
							help={ __( 'For example a receipt, cheque or e-transfer number.', 'open-bulk-buying' ) }
							value={ reference }
							disabled={ !! busy }
							onChange={ setReference }
						/>
						<HStack justify="flex-start">
							<Button
								__next40pxDefaultSize
								variant="primary"
								isBusy={ busy === 'record' }
								disabled={ !! busy }
								accessibleWhenDisabled
								onClick={ () =>
									pay(
										'record',
										{ reference: reference.trim() },
										__( 'Membership payment recorded.', 'open-bulk-buying' )
									)
								}
							>
								{ amount
									? /* translators: %s: fee amount. */
									  sprintf( __( 'Record %s payment', 'open-bulk-buying' ), amount )
									: __( 'Record payment', 'open-bulk-buying' ) }
							</Button>
						</HStack>
					</VStack>
				) }

				<FeeHistory history={ history } />
			</VStack>

			{ confirmReject && (
				<ConfirmDialog
					isOpen
					onCancel={ () => setConfirmReject( false ) }
					onConfirm={ () => {
						setConfirmReject( false );
						pay(
							'reject',
							{ order_id: pendingId, reject: true },
							__( 'Claimed membership payment rejected.', 'open-bulk-buying' )
						);
					} }
					confirmButtonText={ __( 'Reject payment', 'open-bulk-buying' ) }
				>
					{ __( 'Reject this claimed payment? Its order is cancelled and the fee stays due.', 'open-bulk-buying' ) }
				</ConfirmDialog>
			) }
		</section>
	);
}
