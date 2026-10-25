import { useSearch } from '../../router';
import CyclesList from './list';
import CycleWorkspace from './workspace';

export default function CyclesScreen() {
	const search = useSearch();
	return search.cycle !== undefined ? (
		<CycleWorkspace cycleId={ search.cycle } />
	) : (
		<CyclesList />
	);
}
