import React, { useEffect, useRef, useState } from "react";
import { Card, Badge } from "react-bootstrap-v5";
import { connect, useDispatch } from "react-redux";
import { posFetchProduct } from "../../../store/action/pos/posfetchProductAction";
import { posAllProduct } from "../../../store/action/pos/posAllProductAction";
import productImage from "../../../assets/images/brand_logo.png";
import { addToast } from "../../../store/action/toastAction";
import {
    currencySymbolHandling,
    getFormattedMessage,
} from "../../../shared/sharedMethod";
import { toastType } from "../../../constants";
import Skelten from "../../../shared/components/loaders/Skelten";

const Product = (props) => {
    const {
        posAllProducts,
        cartProducts,
        updateCart,
        customCart,
        cartProductIds,
        setCartProductIds,
        settings,
        productMsg,
        newCost,
        selectedOption,
        allConfigData,
        isLoading,
        onScrollCallAPI,
        page,
        setPage,
        totalProductRecord
    } = props;
    const [updateProducts, setUpdateProducts] = useState([]);
    const [loadingMore, setLoadingMore] = useState(false);
    const clickAudioRef = useRef(null);
    const dispatch = useDispatch();

    useEffect(() => {
        // update cart while cart is updated
        cartProducts && setUpdateProducts(cartProducts);
        const ids = updateProducts.map((item) => {
            return item.id;
        });
        setCartProductIds(ids);
    }, [updateProducts, cartProducts]);

    const addToCart = (product) => {
        if(product.attributes.stock.quantity > 0.0){
            if (settings?.attributes?.enable_pos_click_audio === 'true' && clickAudioRef.current) {
                clickAudioRef.current.play().catch((e) => {
                    console.warn("Audio play failed:", e);
                });
            }
            addProductToCart(product);
        } else {
            dispatch(
                addToast({
                    text: getFormattedMessage(
                        "pos.quantity.exceeds.quantity.available.in.stock.message"
                    ),
                    type: toastType.ERROR,
                })
            );
        }
    };

    const handleScroll = () => {
        if (loadingMore) return; // Prevent multiple API calls

        const scrollableDiv = document.querySelector(".product-list-block");
        if (scrollableDiv) {
            const { scrollTop, scrollHeight, clientHeight } = scrollableDiv;

            if (scrollTop + clientHeight >= scrollHeight - 20) {
                // User reached near bottom
                if (posAllProducts.length < totalProductRecord) {
                    setLoadingMore(true);
                    onScrollCallAPI(page + 1); // Call API with next page
                    setPage((prevPage) => prevPage + 1);
                }
            }
        }
    };

    const handleLoadMoreClick = () => {
        if (!loadingMore && posAllProducts.length < totalProductRecord) {
            setLoadingMore(true);
            onScrollCallAPI(page + 1);
            setPage((prevPage) => prevPage + 1);
        }
    };
    
    useEffect(() => {
        const scrollableDiv = document.querySelector(".product-list-block");
        if (scrollableDiv) {
            scrollableDiv.addEventListener("scroll", handleScroll);
        }

        return () => {
            if (scrollableDiv) {
                scrollableDiv.removeEventListener("scroll", handleScroll);
            }
        };
    }, [page, loadingMore, posAllProducts, totalProductRecord]); // Run effect when page or loading state changes

    useEffect(() => {
        setLoadingMore(false);
    }, [posAllProducts]);

    const addProductToCart = (product) => {
        const newId = posAllProducts
            .filter((item) => item.id === product.id)
            .map((item) => item.id);
        const finalIdArrays = customCart.map((id) => id.product_id);
        const finalId = finalIdArrays.filter(
            (finalIdArray) => finalIdArray === newId[0]
        );
        const pushArray = [...customCart];
        const newProduct = pushArray.find(
            (element) => element.id === finalId[0]
        );
        const filterQty = updateProducts
            .filter((item) => item.id === product.id)
            .map((qty) => qty.quantity)[0];
        if (
            updateProducts.filter((item) => item.id === product.id).length > 0
        ) {
            if (filterQty >= product.attributes.stock.quantity) {
                dispatch(
                    addToast({
                        text: getFormattedMessage(
                            "pos.quantity.exceeds.quantity.available.in.stock.message"
                        ),
                        type: toastType.ERROR,
                    })
                );
            } else if (product.attributes.quantity_limit && filterQty >= product.attributes.quantity_limit) {
                dispatch(
                    addToast({
                        text: getFormattedMessage(
                            "sale.product-qty.limit.validate.message"
                        ),
                        type: toastType.ERROR,
                    })
                );
            } else {
                setUpdateProducts((updateProducts) =>
                    updateProducts.map((item) =>
                        item.id === product.id
                            ? {
                                ...item,
                                quantity:
                                    product.attributes.stock.quantity >
                                        item.quantity
                                        ? item.quantity++ + 1
                                        : null,
                            }
                            : { ...item, id: item.id }
                    )
                );
                updateCart(updateProducts, {...product,warehouse_id: selectedOption.value, image: product.attributes.images.imageUrls ? product.attributes.images.imageUrls[0] : productImage });
            }
        } else {
            setUpdateProducts((prevSelected) => [...prevSelected, {...product,warehouse_id: selectedOption.value}]);
            updateCart((prevSelected) => [...prevSelected, {...newProduct,warehouse_id: selectedOption.value, image: product.attributes.images.imageUrls ? product.attributes.images.imageUrls[0] : productImage}]);
        }
    };

    const isProductExistInCart = (productId) => {
        return cartProductIds.includes(productId);
    };

    const posFilterProduct = posAllProducts &&
        settings?.attributes?.show_pos_stock_product === 'true'
        ? posAllProducts :
        posAllProducts.filter(
            (product) => product?.attributes?.stock?.quantity > 0.0
        );

    const FALLBACK_GROCERY_IMG = "/uploads/freshmart/products/fallback-grocery.webp";

    //Cart Item Array
    const loadAllProduct = (product, index) => {
        const qty = Number(product.attributes?.stock?.quantity ?? 0);
        const alertThreshold = Number(product.attributes?.stock_alert ?? 10);
        const stockState = qty <= 0 ? "out" : qty <= alertThreshold ? "low" : "ok";
        const stockLabel =
            qty <= 0
                ? "Out of Stock"
                : qty <= alertThreshold
                ? `Low: ${qty}`
                : `${qty} in stock`;
        const imgSrc =
            product.attributes?.images?.thumbUrl ||
            (product.attributes?.images?.imageUrls && product.attributes.images.imageUrls[0]) ||
            FALLBACK_GROCERY_IMG;

        return (
            <div
                className="product-custom-card fm-pos-card-wrap"
                key={index}
                onClick={() => addToCart(product)}
            >
                <Card
                    className={`position-relative h-100 fm-pos-card ${
                        isProductExistInCart(product.id) ? "product-active" : ""
                    }`}
                >
                    <div className="fm-pos-img-stage">
                        <img
                            src={imgSrc}
                            alt={product.attributes?.name || "Grocery Product"}
                            loading="lazy"
                            onError={(e) => {
                                e.currentTarget.onerror = null;
                                e.currentTarget.src = productImage;
                            }}
                        />
                        <span className={`fm-stock-pill fm-stock-pill--${stockState}`}>
                            {stockLabel}
                        </span>
                    </div>
                    <Card.Body className="fm-pos-body">
                        <h6 className="fm-pos-title" title={product.attributes?.name}>
                            {product.attributes?.name}
                        </h6>
                        <div className="fm-pos-meta">
                            <span className="text-truncate">
                                {product.attributes?.brand_name || product.attributes?.product_category_name || "FreshMart"}
                            </span>
                            <span className="fm-pos-sku">
                                {product.attributes?.code}
                            </span>
                        </div>
                        <div className="fm-pos-price-row">
                            <span className="fm-pos-price">
                                {currencySymbolHandling(
                                    allConfigData,
                                    settings.attributes &&
                                        settings.attributes.currency_symbol,
                                    newCost
                                        ? newCost
                                        : product.attributes.product_price
                                )}
                            </span>
                            <span className="fm-pos-unit">
                                {product?.attributes?.variation_product?.variation_type_name ||
                                    product?.attributes?.sale_unit_name?.short_name ||
                                    "1pc"}
                            </span>
                        </div>
                    </Card.Body>
                </Card>
            </div>
        );
    };

    return (
        <div
            className={`${
                posFilterProduct && posFilterProduct.length === 0
                    ? "d-flex align-items-center justify-content-center"
                    : ""
                } product-list-block pt-1`}
        >
            <audio ref={clickAudioRef} src={settings?.attributes?.click_audio} preload="auto" />
            <div className="d-flex flex-wrap product-list-block__product-block w-100">
                {posFilterProduct && posFilterProduct.length === 0 ? (
                    isLoading ? (
                        <Skelten />
                    ) : (
                        <h4 className="m-auto">
                            {getFormattedMessage(
                                "pos-no-product-available.label"
                            )}
                        </h4>
                    )
                ) : (
                    ""
                )}
                {productMsg && productMsg === 1 ? (
                    <h4 className="m-auto">
                        {getFormattedMessage("pos-no-product-available.label")}
                    </h4>
                ) : (
                    posFilterProduct &&
                    posFilterProduct.map((product, index) => {
                        return loadAllProduct(product, index);
                    })
                )}

                {posAllProducts.length < totalProductRecord && !isLoading && (
                    <div className="d-flex justify-content-center w-100 my-3">
                        <button
                            className="btn btn-outline-primary"
                            onClick={handleLoadMoreClick}
                            disabled={loadingMore}
                        >
                            {loadingMore ? getFormattedMessage("loading.title") : getFormattedMessage("load.more.title")}
                        </button>
                    </div>
                )}

                {isLoading && (
                    <div className="d-flex justify-content-center w-100 text-primary">
                        <div className="spinner-border" role="status">
                            <span className="visually-hidden">Loading...</span>
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
};

const mapStateToProps = (state) => {
    const { posAllProducts, allConfigData, isLoading, totalProductRecord } = state;
    return { posAllProducts, allConfigData, isLoading, totalProductRecord };
};

export default connect(mapStateToProps, { posAllProduct, posFetchProduct })(
    Product
);
