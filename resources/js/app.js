import './bootstrap';
import './search-suggest';
import './cart-add';

import Alpine from 'alpinejs';
import megaMenu from './mega-menu';
import mobileMenu from './mobile-menu';
import adminImages from './admin-images';

window.Alpine = Alpine;

Alpine.data('megaMenu', megaMenu);
Alpine.data('mobileMenu', mobileMenu);
Alpine.data('adminImages', adminImages);

Alpine.start();
