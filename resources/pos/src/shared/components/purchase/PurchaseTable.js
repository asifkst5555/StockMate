import React, { useEffect, useState } from "react";
import { InputGroup } from "react-bootstrap-v5";
import { connect, useDispatch } from "react-redux";
import ProductModal from "./ProductModal";
import Form from "react-bootstrap/Form";
import {
    taxAmountMultiply,
    discountAmountMultiply,
    subTotalCount,
    amountBeforeTax,
} from "../../calculation/calculation";
import { productUnitDropdown } from "../../../store/action/productUnitAction";
import { currencySymbolHandling, decimalValidate, getFormattedMessage } from "../../sharedMethod";
import { FontAwesomeIcon } from "@fortawesome/react-fontawesome";
import { faPencil, faTrash } from "@fortawesome/free-solid-svg-icons";
import { addToast } from "../../../store/action/toastAction";

const PurchaseTable = (props) => {
    const {
        singleProduct,
        index,
        updateCost,
        updateDiscount,
        updateProducts,
        setUpdateProducts,
        frontSetting,
        updateTax,
        updateSubTotal,
        productUnitDropdown,
        productUnits,
        updatePurchaseUnit,
        allConfigData,
        warehouseName,
        isPurchaseReturn = false
    } = props;
    const [updateData, setUpdateData] = useState([]);
    const [isOpen, setIsOpen] = useState(false);
    const [modalId, setModalId] = useState(null);
    const dispatch = useDispatch();

    useEffect(() => {
        singleProduct.newItem !== "" &&
            productUnitDropdown(singleProduct.product_unit);
    }, [updateData, singleProduct.purchase_unit]);

    const withUpdatedQuantity = (item, newQuantity) => {
        const updated = { ...item, quantity: newQuantity };
        return {
            ...updated,
            sub_total: Number(subTotalCount(updated)),
        };
    };

    const onDeleteCartItem = (id) => {
        const newProduct = updateProducts.filter((item) => item.id !== id);
        setUpdateProducts(newProduct);
        dispatch(addToast({ text: getFormattedMessage("item.deleted.success.message") }));
    };

    const handleClose = (e) => {
        e.preventDefault();
        setIsOpen(!isOpen);
        e.stopPropagation();
        productUnitDropdown(singleProduct.product_unit);
        setModalId(singleProduct.id);
    };

    const onProductUpdateInCart = (item) => {
        setUpdateData(item);
    };

    const handleIncrement = () => {
        setUpdateProducts((updateProducts) =>
            updateProducts.map((item) => {
                if (item.id !== singleProduct.id) {
                    return item;
                }
                const newQuantity = Number(
                    ((Number(item.quantity) || 0) + 1).toFixed(2)
                );
                return withUpdatedQuantity(item, newQuantity);
            })
        );
    };

    const handleDecrement = () => {
        const currentQty = Number(singleProduct.quantity) || 0;
        const minQuantity = isPurchaseReturn ? 0 : 1;
        if (currentQty > minQuantity) {
            const newQuantity = Math.max(
                minQuantity,
                Number((currentQty - 1).toFixed(2))
            );
            setUpdateProducts((updateProducts) =>
                updateProducts.map((item) =>
                    item.id === singleProduct.id
                        ? withUpdatedQuantity(item, newQuantity)
                        : item
                )
            );
        }
    };

    const handleChange = (e) => {
        e.preventDefault();
        const { value } = e.target;
        // check if value includes a decimal point
        if (value.match(/\./g)) {
            const [, decimal] = value.split(".");
            // restrict value to only 2 decimal places
            if (decimal?.length > 2) {
                // do nothing
                return;
            }
        }
        const parsedQty = Math.max(0, Number(value) || 0);
        setUpdateProducts((updateProducts) =>
            updateProducts.map((item) =>
                item.id === singleProduct.id
                    ? withUpdatedQuantity(item, parsedQty)
                    : item
            )
        );
    };

    const matchedWarehouseStock = singleProduct?.in_warehouse_stock?.find(
        (item) => item.name === warehouseName
    );
    const resolvedStock = singleProduct?.in_warehouse_stock
        ? matchedWarehouseStock
            ? matchedWarehouseStock.total_quantity
            : 0
        : singleProduct.stock;

    return (
        <>
            <tr key={index} className="align-middle text-nowrap">
                <td className="ps-3">
                    <h4 className="product-name">{singleProduct.name}</h4>
                    <div className="d-flex align-items-center">
                        <span className="badge bg-light-success">
                            <span>{singleProduct.code}</span>
                        </span>
                        <span className="badge bg-light-primary p-1 ms-1">
                            <FontAwesomeIcon
                                icon={faPencil}
                                onClick={(e) => handleClose(e)}
                                style={{ cursor: "pointer" }}
                            />
                        </span>
                    </div>
                </td>
                <td>
                    {currencySymbolHandling(
                        allConfigData,
                        frontSetting.value &&
                            frontSetting.value.currency_symbol,
                        amountBeforeTax(singleProduct)
                    )}
                </td>
                <td>
                    {singleProduct.isEdit ? (
                        Array.isArray(singleProduct.stocks) &&
                        singleProduct.stocks.length >= 1 ? (
                            singleProduct.stocks.map((item, stockIndex) => {
                                return (
                                    <span
                                        key={item.warehouse_id ?? stockIndex}
                                        className="badge bg-light-warning"
                                    >
                                        <span>
                                            {item.quantity}&nbsp;
                                            {singleProduct.short_name}
                                        </span>
                                    </span>
                                );
                            })
                        ) : (
                            <span className="badge bg-light-warning">
                                <span>0 &nbsp;{singleProduct.short_name}</span>
                            </span>
                        )
                    ) : resolvedStock > 0 ? (
                        <span className="badge bg-light-warning">
                            <span>
                                {resolvedStock}&nbsp;
                                {singleProduct.short_name}
                            </span>
                        </span>
                    ) : (
                        <span className="badge bg-light-warning">
                            <span>0 &nbsp;{singleProduct.short_name}</span>
                        </span>
                    )}
                </td>
                <td>
                    <div className="custom-qty">
                        <InputGroup className="flex-nowrap">
                            <InputGroup.Text
                                className="btn btn-primary btn-sm px-4 pt-2"
                                onClick={() => handleDecrement()}
                            >
                                -
                            </InputGroup.Text>
                            <Form.Control
                                aria-label="Product Quantity"
                                onKeyPress={(event) => decimalValidate(event)}
                                className="text-center px-0 py-2 rounded-0 hide-arrow"
                                value={singleProduct.quantity}
                                type="number"
                                step={0.01}
                                min={0.0}
                                onChange={(e) => handleChange(e)}
                            />
                            <InputGroup.Text
                                className="btn btn-primary btn-sm px-4 px-4 pt-2"
                                onClick={(e) => handleIncrement(e)}
                            >
                                +
                            </InputGroup.Text>
                        </InputGroup>
                    </div>
                </td>
                <td>
                    {currencySymbolHandling(
                        allConfigData,
                        frontSetting.value &&
                            frontSetting.value.currency_symbol,
                        discountAmountMultiply(singleProduct)
                    )}
                </td>
                <td>
                    {currencySymbolHandling(
                        allConfigData,
                        frontSetting.value &&
                            frontSetting.value.currency_symbol,
                        taxAmountMultiply(singleProduct)
                    )}
                </td>
                <td>
                    {currencySymbolHandling(
                        allConfigData,
                        frontSetting.value &&
                            frontSetting.value.currency_symbol,
                        subTotalCount(singleProduct)
                    )}
                </td>
                <td className="text-start">
                    <button className="btn px-2 text-danger fs-3">
                        <FontAwesomeIcon
                            icon={faTrash}
                            onClick={() => onDeleteCartItem(singleProduct.id)}
                        />
                    </button>
                </td>
            </tr>
            {isOpen && (
                <ProductModal
                    handleClose={handleClose}
                    setIsOpen={setIsOpen}
                    show={isOpen}
                    modalId={modalId}
                    isOpen={isOpen}
                    frontSetting={frontSetting}
                    product={singleProduct}
                    id={singleProduct.id}
                    productUnits={productUnits}
                    updatePurchaseUnit={updatePurchaseUnit}
                    updateProducts={updateProducts}
                    title={singleProduct.name}
                    onProductUpdateInCart={onProductUpdateInCart}
                    updateSubTotal={updateSubTotal}
                    updateCost={updateCost}
                    updateDiscount={updateDiscount}
                    updateTax={updateTax}
                />
            )}
        </>
    );
};

const mapStateToProps = (state) => {
    const { productUnits, frontSetting, allConfigData } = state;
    return { productUnits, frontSetting, allConfigData };
};

export default connect(mapStateToProps, { productUnitDropdown })(PurchaseTable);
