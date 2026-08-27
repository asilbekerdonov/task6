import './echo';
import { initUi, loadCircuitOptions } from './modules/ui.js';
import { bindCursorWhisper } from './modules/realtime.js';

initUi();
bindCursorWhisper();
loadCircuitOptions();
