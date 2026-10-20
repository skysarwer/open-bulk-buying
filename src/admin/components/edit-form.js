import {
	Button,
	__experimentalHStack as HStack,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { DataForm, useFormValidity } from '@wordpress/dataviews/wp';

import ErrorNotice from './error-notice';
import useUnsavedChanges, { discardUnsavedChanges } from '../hooks/use-unsaved-changes';

/**
 * A DataForm with Save/Cancel buttons. Holds the edits on top of `initial`;
 * onSubmit( data, edits ) returns a promise, and a rejection's message is shown.
 * The edits are cleared after a successful save, so pass the saved item back
 * in as `initial` to keep showing it.
 *
 * `fields` may be a function of the current data; it must return the same array
 * while nothing it depends on changes, or controls configured with an object
 * `Edit` (e.g. a textarea) remount and lose focus on each keystroke. DataForm only re-validates a
 * field when its own value changes, so rules that span fields go in
 * `validate( data )`, which returns an error message or null.
 *
 * With `requireChanges`, Save stays disabled until something changes and Cancel
 * becomes "Discard changes" while there are edits.
 *
 * @param {Object} props
 */
export default function EditForm( {
	fields: fieldsProp,
	form,
	initial,
	onSubmit,
	onCancel,
	validate,
	submitLabel = __( 'Save', 'open-bulk-buying' ),
	cancelLabel = __( 'Cancel', 'open-bulk-buying' ),
	requireChanges = false,
	className = '',
	children,
} ) {
	const [ edits, setEdits ] = useState( {} );
	const [ isBusy, setIsBusy ] = useState( false );
	const [ error, setError ] = useState( null );
	const data = { ...initial, ...edits };
	const fields =
		typeof fieldsProp === 'function' ? fieldsProp( data ) : fieldsProp;
	const { validity, isValid } = useFormValidity( data, fields, form );
	const isDirty = Object.keys( edits ).length > 0;
	const canDiscard = requireChanges && isDirty;
	useUnsavedChanges( isDirty );

	const cancel = () => {
		if ( canDiscard ) {
			setEdits( {} );
			return;
		}
		// Pressing Cancel is consent to lose the edits; don't ask again.
		discardUnsavedChanges();
		onCancel();
	};

	const submit = async ( event ) => {
		event.preventDefault();
		const invalid = validate?.( data );
		if ( invalid ) {
			setError( invalid );
			return;
		}
		setIsBusy( true );
		setError( null );
		try {
			await onSubmit( data, edits );
			setEdits( {} );
		} catch ( err ) {
			setError( err );
		}
		setIsBusy( false );
	};

	return (
		<form onSubmit={ submit } className={ `openbb-edit-form ${ className }` }>
			<VStack spacing={ 4 }>
				<ErrorNotice error={ error } onDismiss={ () => setError( null ) } />
				{ children }
				<DataForm
					data={ data }
					fields={ fields }
					form={ form }
					validity={ validity }
					onChange={ ( changes ) =>
						setEdits( ( prev ) => ( { ...prev, ...changes } ) )
					}
				/>
				<HStack justify="flex-end" className="openbb-form-actions">
					<Button
						__next40pxDefaultSize
						variant="tertiary"
						onClick={ cancel }
						disabled={ isBusy }
					>
						{ canDiscard
							? __( 'Discard changes', 'open-bulk-buying' )
							: cancelLabel }
					</Button>
					<Button
						__next40pxDefaultSize
						variant="primary"
						type="submit"
						isBusy={ isBusy }
						disabled={
							isBusy || ! isValid || ( requireChanges && ! isDirty )
						}
						accessibleWhenDisabled
					>
						{ submitLabel }
					</Button>
				</HStack>
			</VStack>
		</form>
	);
}
