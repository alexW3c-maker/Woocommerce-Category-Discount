jQuery(document).ready(function ($) {
    function update_free_product() {
        let product_id = $("#add_free_product").val();
        let discount_category_count = parseInt($("#discount_category_count").val());
        let discount_threshold = parseInt($("#discount_threshold").val());
    
        if (discount_category_count >= discount_threshold) {
            if (product_id !== "") {
                $.ajax({
                    url: category_discount_ajax_object.ajax_url,
                    type: "POST",
                    data: {
                        action: "add_free_product",
                        product_id: product_id,
                    },
                    success: function (response) {
                        if (response.success) {
                            $.ajax({
                                url: wc_cart_fragments_params.wc_ajax_url.toString().replace('%%endpoint%%', 'get_refreshed_fragments'),
                                type: 'POST',
                                success: function (data) {
                                    if (data && data.fragments) {
                                        $.each(data.fragments, function (key, value) {
                                            $(key).replaceWith(value);
                                        });
                        
                                        $(document.body).trigger('wc_fragments_refreshed');
                                        location.reload();
                                    }
                                }
                            });
                        }
                    },
                    error: function () {
                        alert("Error: Could not add the product to cart.");
                    },
                });
            }
        }
    }

    $(document).on("click", "#add_free_product_button", function (e) {
        e.preventDefault();
        update_free_product();
    });

    $("div.woocommerce").on("change", "input.qty", function () {
        $("button[name='update_cart']").prop("disabled", false);
        $("button[name='update_cart']").trigger("click");
    });

    $("div.woocommerce").on("click", "a.remove", function () {
        setTimeout(function () {
            $("button[name='update_cart']").prop("disabled", false);
            $("button[name='update_cart']").trigger("click");
        }, 500);
    });

    $(document).ajaxComplete(function (event, xhr, settings) {
        if (settings.url.includes("wc-ajax=update_order_review")) {
            update_free_product();
        }
    });

    $(document.body).on('updated_wc_div', function () {
        update_free_product();
    });
});