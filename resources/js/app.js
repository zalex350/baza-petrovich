import './bootstrap';
import './search-suggest';
import './cart-add';

import Alpine from 'alpinejs';
import megaMenu from './mega-menu';
import mobileMenu from './mobile-menu';
import adminImages from './admin-images';
import productGallery from './product-gallery';

window.Alpine = Alpine;

Alpine.data('megaMenu', megaMenu);
Alpine.data('mobileMenu', mobileMenu);
Alpine.data('adminImages', adminImages);
Alpine.data('productGallery', productGallery);

Alpine.start();
