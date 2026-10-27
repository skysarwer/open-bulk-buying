import {
	Button,
	Modal,
	TextControl,
	__experimentalHStack as HStack,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { plus } from '@wordpress/icons';

import { ErrorNotice, Loading, Page } from '../../components';
import { useNotify } from '../../hooks';
import { request } from '../../utils/api';

function TermModal( { taxonomy, term, onClose, onSaved } ) {
	const isCategory = taxonomy === 'categories';
	const [ name, setName ] = useState( term?.name ?? '' );
	const [ order, setOrder ] = useState( String( term?.order ?? 0 ) );
	const [ isBusy, setIsBusy ] = useState( false );
	const [ error, setError ] = useState( null );

	const submit = async ( event ) => {
		event.preventDefault();
		setIsBusy( true );
		try {
			if ( term ) {
				await request( `/product-terms/categories/${ term.id }`, {
					method: 'PUT',
					data: { order: Number( order ) },
				} );
			} else {
				await request( `/product-terms/${ taxonomy }`, {
					method: 'POST',
					data: isCategory
						? { name, order: Number( order ) }
						: { name },
				} );
			}
			onSaved();
		} catch ( err ) {
			setError( err );
			setIsBusy( false );
		}
	};

	let title;
	if ( term ) {
		title = __( 'Edit category order', 'open-bulk-buying' );
	} else if ( isCategory ) {
		title = __( 'Add category', 'open-bulk-buying' );
	} else {
		title = __( 'Add supplier', 'open-bulk-buying' );
	}

	return (
		<Modal title={ title } onRequestClose={ onClose } size="small">
			<form onSubmit={ submit }>
				<VStack spacing={ 4 }>
					<ErrorNotice error={ error } />
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Name', 'open-bulk-buying' ) }
						value={ name }
						onChange={ setName }
						disabled={ !! term }
						required
					/>
					{ isCategory && (
						<TextControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							label={ __( 'Display order', 'open-bulk-buying' ) }
							help={ __(
								'Lower numbers appear first. Categories with the same order sort by name.',
								'open-bulk-buying'
							) }
							type="number"
							step="1"
							value={ order }
							onChange={ setOrder }
							required
						/>
					) }
					<HStack justify="flex-end">
						<Button variant="tertiary" onClick={ onClose }>
							{ __( 'Cancel', 'open-bulk-buying' ) }
						</Button>
						<Button
							variant="primary"
							type="submit"
							isBusy={ isBusy }
							disabled={ isBusy || ! name.trim() }
						>
							{ term
								? __( 'Save', 'open-bulk-buying' )
								: __( 'Add', 'open-bulk-buying' ) }
						</Button>
					</HStack>
				</VStack>
			</form>
		</Modal>
	);
}

/**
 * A list of product suppliers or categories, with adding (and, for categories,
 * editing the display order).
 *
 * @param {Object}  props
 * @param {string}  props.taxonomy 'suppliers' or 'categories'.
 * @param {Element} props.nav      The tab links, from the Products screen.
 */
export default function TermsTab( { taxonomy, nav } ) {
	const isCategory = taxonomy === 'categories';
	const [ terms, setTerms ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ editing, setEditing ] = useState( undefined );
	const notify = useNotify();

	const load = useCallback( () => {
		request( `/product-terms/${ taxonomy }` )
			.then( ( items ) => {
				setError( null );
				setTerms( items );
			} )
			.catch( ( err ) => {
				setError( err );
				setTerms( ( current ) => current ?? [] );
			} );
	}, [ taxonomy ] );
	useEffect( load, [ load ] );

	const addLabel = isCategory
		? __( 'Add category', 'open-bulk-buying' )
		: __( 'Add supplier', 'open-bulk-buying' );

	return (
		<Page
			title={ __( 'Products', 'open-bulk-buying' ) }
			actions={
				<Button
					variant="primary"
					icon={ plus }
					onClick={ () => setEditing( null ) }
				>
					{ addLabel }
				</Button>
			}
		>
			{ nav }
			<ErrorNotice error={ error } />
			{ ! terms ? (
				<Loading />
			) : (
				<table className="wp-list-table widefat striped">
					<thead>
						<tr>
							<th>{ __( 'Name', 'open-bulk-buying' ) }</th>
							{ isCategory && (
								<th>{ __( 'Display order', 'open-bulk-buying' ) }</th>
							) }
							<th>{ __( 'Products', 'open-bulk-buying' ) }</th>
							{ isCategory && <th /> }
						</tr>
					</thead>
					<tbody>
						{ ! terms.length && (
							<tr>
								<td colSpan={ isCategory ? 4 : 2 }>
									{ isCategory
										? __( 'No categories yet.', 'open-bulk-buying' )
										: __( 'No suppliers yet.', 'open-bulk-buying' ) }
								</td>
							</tr>
						) }
						{ terms.map( ( term ) => (
							<tr key={ term.id }>
								<td>{ term.name }</td>
								{ isCategory && <td>{ term.order }</td> }
								<td>{ term.count }</td>
								{ isCategory && (
									<td>
										<Button
											variant="link"
											onClick={ () => setEditing( term ) }
										>
											{ __( 'Edit order', 'open-bulk-buying' ) }
										</Button>
									</td>
								) }
							</tr>
						) ) }
					</tbody>
				</table>
			) }
			{ editing !== undefined && (
				<TermModal
					taxonomy={ taxonomy }
					term={ editing }
					onClose={ () => setEditing( undefined ) }
					onSaved={ () => {
						setEditing( undefined );
						load();
						notify.success( __( 'Saved.', 'open-bulk-buying' ) );
					} }
				/>
			) }
		</Page>
	);
}
