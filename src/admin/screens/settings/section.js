import {
	Card,
	CardBody,
	CardHeader,
	__experimentalVStack as VStack,
} from '@wordpress/components';

export default function Section( { title, isDanger, actions, children } ) {
	return (
		<Card
			className={
				'openbb-settings__section' + ( isDanger ? ' is-danger' : '' )
			}
		>
			<CardHeader>
				<h3 className="openbb-settings__title">{ title }</h3>
				{ actions }
			</CardHeader>
			<CardBody>
				<VStack spacing={ 4 }>{ children }</VStack>
			</CardBody>
		</Card>
	);
}
