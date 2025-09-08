<?php
// Minimal single-collection template
get_header();
global $post;
$product_ids = get_post_meta( $post->ID, '_collection_product_ids', true );
if ( ! is_array( $product_ids ) ) $product_ids = array();
?>
<main class="collection-single">
    <h1><?php the_title(); ?></h1>
    <div class="collection-content"><?php the_content(); ?></div>

    <h3>Products in this collection</h3>
    <ul>
    <?php foreach ( $product_ids as $pid ) :
        $p = wc_get_product( $pid );
        if ( ! $p ) continue;
    ?>
        <li>
            <a href="<?php echo get_permalink( $pid ); ?>"><?php echo esc_html( $p->get_name() ); ?></a> —
            <?php echo wc_price( $p->get_price() ); ?>
        </li>
    <?php endforeach; ?>
    </ul>

    <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">
        <?php wp_nonce_field( 'wc_collections_purchase', '_wpnonce' ); ?>
        <input type="hidden" name="action" value="wc_collections_purchase_all">
        <input type="hidden" name="collection_id" value="<?php echo esc_attr( $post->ID ); ?>">
        <button type="submit">Purchase all</button>
    </form>
</main>
<?php get_footer(); ?>
