import { profileFieldAdminLabel } from '../src/admin/utils/config';
import {
	fromDraft,
	toDraft,
} from '../src/admin/screens/settings/profile-fields/utils';
import {
	profileFormFields,
	profileListFields,
	withFieldErrors,
} from '../src/admin/screens/members/profile';

jest.mock( '@wordpress/components', () => ( {
	Button: jest.fn(),
	SelectControl: jest.fn(),
} ) );
jest.mock( '../src/admin/utils/api', () => ( { request: jest.fn() } ) );

const field = {
	key: 'newsletter',
	label: { default: 'Newsletter', fr: 'Infolettre' },
	type: 'boolean',
};

describe( 'profile field admin labels', () => {
	it( 'keeps legacy labels, including translations and key fallback', () => {
		expect( profileFieldAdminLabel( field, '' ) ).toBe( 'Newsletter' );
		expect( profileFieldAdminLabel( field, 'fr' ) ).toBe( 'Infolettre' );
		expect( profileFieldAdminLabel( { key: 'legacy' }, '' ) ).toBe(
			'legacy'
		);
	} );

	it( 'prefers the localized admin label without modifying the regular label', () => {
		const definition = {
			...field,
			admin_label: { default: 'Mailing list', fr: 'Liste' },
		};
		expect( profileFieldAdminLabel( definition, 'fr' ) ).toBe( 'Liste' );
		expect( profileFieldAdminLabel( definition, 'de' ) ).toBe(
			'Mailing list'
		);
		expect( definition.label ).toEqual( field.label );
	} );

	it( 'falls back to the regular label when the admin label is blank', () => {
		expect(
			profileFieldAdminLabel(
				{ ...field, admin_label: { default: '', fr: '' } },
				'fr'
			)
		).toBe( 'Infolettre' );
		expect(
			profileFieldAdminLabel(
				{ ...field, admin_label: { default: '', fr: 'Liste' } },
				''
			)
		).toBe( 'Newsletter' );
	} );

	it( 'initializes new and legacy editor drafts with an empty admin label', () => {
		expect( toDraft( null ).admin_label ).toEqual( { default: '' } );
		expect( fromDraft( toDraft( field ) ).admin_label ).toEqual( {
			default: '',
		} );
	} );

	it( 'preserves and clears admin labels without changing the field key', () => {
		const definition = {
			...field,
			admin_label: { default: 'Mailing list', fr: 'Liste' },
		};
		const draft = toDraft( definition );
		expect( fromDraft( draft ).admin_label ).toEqual(
			definition.admin_label
		);
		draft.admin_label = { default: '' };
		const cleared = fromDraft( draft );
		expect( cleared.admin_label ).toEqual( { default: '' } );
		expect( cleared.key ).toBe( field.key );
		expect( cleared.label ).toEqual( field.label );
	} );

	it( 'uses admin labels in member columns, filters, forms and errors', () => {
		const definition = {
			...field,
			admin_label: { default: 'Mailing list' },
		};
		const column = profileListFields( [ definition ] )[ 0 ];
		expect( column.label ).toBe( 'Mailing list' );
		expect( column.id ).toBe( 'profile__newsletter' );
		expect( column.filterBy ).toEqual( { operators: [ 'is' ] } );
		for ( const context of [ 'staff', 'staff_create' ] ) {
			const formField = profileFormFields( [ definition ], context )[ 0 ];
			expect( formField.label ).toBe( 'Mailing list' );
			expect( formField.id ).toBe( column.id );
		}
		expect(
			withFieldErrors(
				{
					message: 'Invalid profile.',
					data: { errors: { newsletter: 'Check this box.' } },
				},
				[ definition ]
			).message
		).toBe( 'Invalid profile. Mailing list: Check this box.' );
	} );
} );
