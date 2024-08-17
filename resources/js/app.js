import './bootstrap';

import Alpine from 'alpinejs';
import registerSimulator from './simulator';

window.Alpine = Alpine;

registerSimulator(Alpine);

Alpine.start();
