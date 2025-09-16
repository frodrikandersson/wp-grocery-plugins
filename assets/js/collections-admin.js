jQuery(function($){
    var $sel = $('#wc-collection-products');
    if ($sel.length) {
        $sel.select2({
            ajax: {
                url: ajaxurl,
                dataType: 'json',
                delay: 250,
                data: function(params){
                    return {
                        action: 'wc_collections_admin_search_products',
                        q: params.term,
                        nonce: WCCollectionsAdmin.nonce
                    }
                },
                processResults: function(data){
                    return { results: data.items };
                }
            },
            minimumInputLength: 1
        });
    }
});
