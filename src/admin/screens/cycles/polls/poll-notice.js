import { Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import { ErrorNotice } from '../../../components';
import { STALE } from './utils';

/**
 * Why a save failed. One refused because the poll changed in the meantime
 * offers to reload the poll.
 *
 * @param {Object}   props
 * @param {Object}   props.error
 * @param {string}   props.hint      What to do after reloading.
 * @param {Function} props.onReload
 * @param {Function} props.onDismiss
 */
export default function PollNotice( { error, hint, onReload, onDismiss } ) {
	if ( error?.code !== STALE ) {
		return <ErrorNotice error={ error } onDismiss={ onDismiss } />;
	}

	return (
		<Notice
			status="error"
			isDismissible={ false }
			actions={ [
				{
					label: __( 'Reload poll', 'open-bulk-buying' ),
					onClick: onReload,
				},
			] }
		>
			{ error.message } { hint }
		</Notice>
	);
}
