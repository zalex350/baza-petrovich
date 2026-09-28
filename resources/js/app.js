import './bootstrap';
import './search-suggest';

import Alpine from 'alpinejs';
import megaMenu from './mega-menu';
import mobileMenu from './mobile-menu';

window.Alpine = Alpine;

Alpine.data('megaMenu', megaMenu);
Alpine.data('mobileMenu', mobileMenu);

Alpine.start();
