jQuery(function($){
    function formatProduct(item) {
        return item.text;
    }

    $('#wc_collection_products_front').select2({
        ajax: {
            url: WCCollections.ajax_url,
            dataType: 'json',
            delay: 250,
            data: function(params){
                return {
                    action: 'wc_collections_search_products',
                    q: params.term
                };
            },
            processResults: function(data){
                return { results: data.items };
            },
            cache: true
        },
        minimumInputLength: 1,
        templateResult: formatProduct,
        templateSelection: formatProduct,
    });


    $('#wc-collection-create-form').on('submit', function(e){
        e.preventDefault();
        var $form = $(this);
        var data = $form.serializeArray();
        data.push({name:'action', value: 'wc_collections_create'});
        data.push({name:'nonce', value: WCCollections.nonce});

        $.post(WCCollections.ajax_url, data, function(resp){
            if (resp.success) {
                var r = resp.data;
                var msg = 'Collection created (status: ' + r.status + ').';
                if (r.coupon_code) msg += ' Coupon: ' + r.coupon_code;
                if (r.coupon_error) msg += ' Coupon error: ' + r.coupon_error;
                if (r.mailgun_error) msg += ' Mail error: ' + r.mailgun_error;
                $('#wc-collection-create-result').html('<div class="notice-success">' + msg + '</div>');

                if (window.dataLayer) {
                    window.dataLayer.push({
                        event: 'collectionCreated',
                        collectionId: r.post_id,
                        couponCode: r.coupon_code || ''
                    });
                }
            } else {
                $('#wc-collection-create-result').html('<div class="notice-error">' + resp.data + '</div>');
            }
        }, 'json');
    });

});
