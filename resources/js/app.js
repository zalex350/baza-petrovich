import './bootstrap';
import './search-suggest';
import './cart-actions';
import './cart-page';

import Alpine from 'alpinejs';
import focus from '@alpinejs/focus';
import megaMenu from './mega-menu';
import mobileMenu from './mobile-menu';
import adminImages from './admin-images';
import imageQueue from './image-queue';
import attributeRows from './attribute-rows';
import catalogFilters from './catalog-filters';
import productGallery from './product-gallery';
import skuSuggest from './sku-suggest';
import copyText from './copy-text';
import sortableList from './sortable-list';

window.Alpine = Alpine;

// x-trap — удержание фокуса в модальных окнах (x-confirm)
Alpine.plugin(focus);

Alpine.data('megaMenu', megaMenu);
Alpine.data('mobileMenu', mobileMenu);
Alpine.data('adminImages', adminImages);
Alpine.data('imageQueue', imageQueue);
Alpine.data('attributeRows', attributeRows);
Alpine.data('catalogFilters', catalogFilters);
Alpine.data('productGallery', productGallery);
Alpine.data('skuSuggest', skuSuggest);
Alpine.data('copyText', copyText);
Alpine.data('sortableList', sortableList);

Alpine.start();
