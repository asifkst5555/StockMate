import React, { useEffect, useRef, useState } from "react";
import { Col } from "react-bootstrap-v5";
import { ReactSearchAutocomplete } from "react-search-autocomplete";
import { connect, useDispatch } from "react-redux";
import {
    posSearchCodeProduct,
    posSearchNameProduct,
} from "../../../store/action/pos/posfetchProductAction";
import {
    getFormattedMessage,
    placeholderText,
} from "../../../shared/sharedMethod";
import { addToast } from "../../../store/action/toastAction";
import { toastType } from "../../../constants";
import productImage from "../../../assets/images/brand_logo.png";
import apiConfig from "../../../config/apiConfig";
import { prepareCartArray } from "../../shared/PrepareCartArray";

const ProductSearchbar = (props) => {
    const {
        posAllProducts,
        customCart,
        setUpdateProducts,
        updateProducts,
        posSearchCodeProduct,
        posSearchNameProduct,
        selectedOption,
        onSearchProduct,
        settings,
    } = props;
    const [searchString, setSearchString] = useState("");
    const [keyDown, setKeyDown] = useState(false);
    const dispatch = useDispatch();
    const clickAudioRef = useRef(null);
    const scanRequestIdRef = useRef(0);
    const inFlightBarcodeRef = useRef(null);

    const filterProduct = (settings?.attributes?.show_pos_stock_product === 'true'
        ? posAllProducts
        : posAllProducts.filter((item) => (item?.attributes?.stock?.quantity ?? 0) > 0)
        ).map((item) => ({
            name: item.attributes.name,
            code: item.attributes.code,
            product_code: item.attributes.product_code,
            id: item.id,
            stock: item.attributes?.stock?.quantity ?? 0,
            unit: item?.attributes?.product_unit_name?.name,
            image: item.attributes?.images?.imageUrls?.[0],
        }));

    const formatResult = (item) => {
        return (
            <div className="d-flex align-items-center gap-4" >
                <img
                    src={item?.image ? item?.image : productImage}
                    alt={item.name}
                    className="search-image"
                />
                <span>
                    {item.code} ({item.name}) - {getFormattedMessage('purchase.order-item.table.stock.column.label')}: {item?.stock} {item?.unit}
                </span>
            </div>
        );
    };

    const removeSearchClass = () => {
        const searchBar = document.getElementsByClassName("search-bar")[0];
        if (
            searchBar &&
            searchBar.firstChild &&
            searchBar.firstChild.firstChild &&
            searchBar.firstChild.firstChild.lastChild
        ) {
            const html = searchBar.firstChild.firstChild.lastChild;
            html.style.display = "none";
        }
    };

    const playClickAudio = () => {
        if (
            settings?.attributes?.enable_pos_click_audio === "true" &&
            clickAudioRef.current
        ) {
            clickAudioRef.current.play().catch((e) => {
                console.warn("Audio play failed:", e);
            });
        }
    };

    const addMatchedProductToCart = (product) => {
        const availableStock = Number(product?.attributes?.stock?.quantity ?? 0);
        if (availableStock <= 0) {
            dispatch(
                addToast({
                    text: getFormattedMessage(
                        "pos.this.product.out.of.stock.message"
                    ),
                    type: toastType.ERROR,
                })
            );
            setSearchString("");
            removeSearchClass();
            return;
        }

        const preparedList = prepareCartArray([product]);
        const newProduct = preparedList[0];
        if (!newProduct) {
            return;
        }

        playClickAudio();
        removeSearchClass();
        setSearchString("");

        const existingItem = updateProducts.find(
            (item) => item.id === product.id || item.code === product.attributes.code
        );

        if (existingItem) {
            if (existingItem.quantity >= availableStock) {
                dispatch(
                    addToast({
                        text: getFormattedMessage(
                            "pos.quantity.exceeds.quantity.available.in.stock.message"
                        ),
                        type: toastType.ERROR,
                    })
                );
            } else {
                setUpdateProducts((prev) =>
                    prev.map((item) =>
                        (item.id === product.id || item.code === product.attributes.code) &&
                        availableStock > item.quantity
                            ? { ...item, quantity: item.quantity + 1 }
                            : item
                    )
                );
                setKeyDown(false);
            }
        } else {
            setUpdateProducts([
                ...updateProducts,
                { ...newProduct, warehouse_id: selectedOption?.value },
            ]);
            setKeyDown(false);
        }
    };

    const [lastScannedCode, setLastScannedCode] = useState(null);

    const onProductSearch = (code) => {
        const codeValue = typeof code === "string" ? code : code?.code;
        const nameValue = typeof code === "string" ? code : code?.name;

        const matchedProduct = posAllProducts.find(
            (item) =>
                item?.attributes?.code === codeValue ||
                item?.attributes?.product_code === codeValue ||
                item?.attributes?.name === nameValue
        );

        if (matchedProduct) {
            if (matchedProduct.attributes.code === codeValue) {
                posSearchCodeProduct(matchedProduct.attributes.code);
            } else if (matchedProduct.attributes.name === nameValue) {
                posSearchNameProduct(matchedProduct.attributes.name);
            }
            addMatchedProductToCart(matchedProduct);
        }
    };

    const handleExactBarcodeScan = async (rawBarcode) => {
        const barcode = (rawBarcode || "").trim();
        if (!barcode || inFlightBarcodeRef.current === barcode) {
            return;
        }

        // Cancel any pending Redux fallback scan so an async posAllProducts update cannot double-add
        setLastScannedCode(null);

        // Fast path: check exact code or product_code in currently loaded products
        const localMatch = posAllProducts.find(
            (item) =>
                item?.attributes?.code === barcode ||
                item?.attributes?.product_code === barcode
        );
        if (localMatch) {
            scanRequestIdRef.current += 1;
            inFlightBarcodeRef.current = null;
            addMatchedProductToCart(localMatch);
            return;
        }

        // Exact server lookup across full tenant catalog (handles >30 products)
        const requestId = ++scanRequestIdRef.current;
        inFlightBarcodeRef.current = barcode;
        try {
            const params = new URLSearchParams({ barcode });
            if (selectedOption?.value) {
                params.append("warehouse_id", selectedOption.value);
            }
            const response = await apiConfig.get(`products/by-barcode?${params.toString()}`);

            // Discard stale out-of-order response if a newer scan superseded this request
            if (requestId !== scanRequestIdRef.current) {
                return;
            }

            const product = response?.data?.data;
            if (product) {
                addMatchedProductToCart(product);
            } else {
                dispatch(
                    addToast({
                        text: getFormattedMessage("no.results.found.message") + `: ${barcode}`,
                        type: toastType.ERROR,
                    })
                );
                setSearchString("");
            }
        } catch (error) {
            if (requestId !== scanRequestIdRef.current) {
                return;
            }
            dispatch(
                addToast({
                    text:
                        error?.response?.data?.message ||
                        `${getFormattedMessage("no.results.found.message")}: ${barcode}`,
                    type: toastType.ERROR,
                })
            );
            setSearchString("");
        } finally {
            if (requestId === scanRequestIdRef.current) {
                inFlightBarcodeRef.current = null;
                inputFocus();
            }
        }
    };

    const handleOnSelect = (result) => {
        if (result) {
            setLastScannedCode(null);
            scanRequestIdRef.current += 1;
            inFlightBarcodeRef.current = null;
            onProductSearch(result);
        }
    };

    const handleOnSearch = (string) => {
        setSearchString(string);
        const trimmed = string.trim();
        if (trimmed !== "") {
            const filteredProducts = settings?.attributes?.show_pos_stock_product === 'true'
                ? posAllProducts
                : posAllProducts.filter((item) => (item?.attributes?.stock?.quantity ?? 0) > 0);

            const exactMatches = filteredProducts.filter(
                (item) =>
                    item?.attributes?.code === trimmed ||
                    item?.attributes?.product_code === trimmed ||
                    item?.attributes?.name === trimmed
            );
            if (exactMatches.length === 1) {
                setLastScannedCode(null);
                scanRequestIdRef.current += 1;
                inFlightBarcodeRef.current = null;
                addMatchedProductToCart(exactMatches[0]);
            } else if (exactMatches.length === 0) {
                onSearchProduct(trimmed);
                setLastScannedCode(trimmed);
            }
        } else {
            setLastScannedCode(null);
        }
    };

    const handleKeyDown = (event) => {
        if ((event.key === "Enter" || event.key === "Tab") && searchString.trim() !== "") {
            event.preventDefault();
            handleExactBarcodeScan(searchString);
        }
    };

    useEffect(() => {
        if (lastScannedCode && posAllProducts.length) {
            onProductSearch(lastScannedCode);
            setLastScannedCode(null);
        }
    }, [posAllProducts]);

    const inputFocus = () => {
        let searchInput = document.querySelector(
            'input[data-test="search-input"]'
        );
        if (searchInput) {
            searchInput.focus();
        }
    };

    useEffect(() => {
        const keyDownHandler = (event) => {
            if (event.altKey && event.code === "KeyQ") {
                event.preventDefault();
                inputFocus();
            }
        };

        document.addEventListener("keydown", keyDownHandler);

        return () => {
            document.removeEventListener("keydown", keyDownHandler);
        };
    }, []);

    return (
        <Col
            className="position-relative my-3 search-bar col-xxl-8 col-lg-12 col-12"
            onKeyDown={handleKeyDown}
        >
            <audio ref={clickAudioRef} src={settings?.attributes?.click_audio} preload="auto" />
            <ReactSearchAutocomplete
                placeholder={placeholderText("pos-globally.search.field.label")}
                items={filterProduct}
                onSearch={handleOnSearch}
                inputSearchString={searchString}
                fuseOptions={{
                    shouldSort: true,
                    threshold: 0.2, // Lower = more strict (default is 0.6)
                    location: 0,
                    distance: 100,
                    minMatchCharLength: 2,
                    keys: ["name", "code", "product_code"],
                }}
                resultStringKeyName="code"
                onSelect={(data) => {
                    handleOnSelect(data);
                }}
                formatResult={formatResult}
                showIcon={false}
                showClear={false}
                autoFocus={true}
                showNoResultsText={getFormattedMessage("no.results.found.message")}
            />
            <i className="bi bi-search fs-2 react-search-icon position-absolute top-0 bottom-0 d-flex align-items-center ms-2" />
        </Col>
    );
};

const mapStateToProps = (state) => {
    const { posAllProducts } = state;
    return { posAllProducts };
};

export default connect(mapStateToProps, {
    posSearchCodeProduct,
    posSearchNameProduct,
})(ProductSearchbar);
