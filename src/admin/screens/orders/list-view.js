export const ORDER_TABS = [ 'all', 'cycle', 'membership' ];

export const MEMBERSHIP_FIELDS = [
	'id',
	'member_name',
	'status',
	'total',
	'submitted_at',
	'paid_at',
	'payment_reference',
	'updated_at',
];

export function createOrderView( tab, cycleId = 0 ) {
	return {
		type: 'table',
		search: '',
		page: 1,
		perPage: 20,
		sort: { field: 'id', direction: 'desc' },
		titleField: 'member_name',
		fields:
			tab === 'membership'
				? [
						'id',
						'status',
						'total',
						'submitted_at',
						'paid_at',
						'payment_reference',
				  ]
				: [
						'id',
						'cycle_id',
						'status',
						'lines_count',
						'total',
						'aid_status',
						'submitted_at',
				  ],
		filters:
			tab !== 'membership' && cycleId
				? [ { field: 'cycle_id', operator: 'is', value: cycleId } ]
				: [],
	};
}
