<?php
$flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP;
?>
<div class="selection-block w-100">
    <?=$html?>
    <script>
    (function () {
        var root = document.currentScript.parentNode;
        var event = <?=json_encode($event, $flags)?>;
        window.dataLayer = window.dataLayer || [];
        window.dataLayer.push({ ecommerce: null });
        window.dataLayer.push(event);
        // Al clic su una card, `select_item` con l'articolo nella stessa posizione.
        root.addEventListener('click', function (e) {
            var link = e.target.closest('.product-card a[href]');
            if (!link || link.closest('.product-card__variants')) { return; }
            var cards = Array.prototype.slice.call(root.querySelectorAll('.product-card'));
            var item = event.ecommerce.items[cards.indexOf(link.closest('.product-card'))];
            if (!item) { return; }
            window.dataLayer.push({ ecommerce: null });
            window.dataLayer.push({ event: 'select_item', ecommerce: { item_list_id: event.ecommerce.item_list_id, item_list_name: event.ecommerce.item_list_name, items: [item] } });
        });
    })();
    </script>
</div>
