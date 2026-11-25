import React from "react";
import { InputGroup } from "react-bootstrap-v5";
import { connect, useDispatch } from "react-redux";
import Form from "react-bootstrap/Form";
import { productSalesDropdown } from "../../../store/action/productSaleUnitAction";
import { decimalValidate, getFormattedMessage, getFormattedOptions } from "../../sharedMethod";
import { FontAwesomeIcon } from "@fortawesome/react-fontawesome";
import { faTrash } from "@fortawesome/free-solid-svg-icons";
import AdjustmentType from "../../../shared/option-lists/AdjustmentType.json";
import ReactSelect from "../../../shared/select/reactSelect";
import { posAllProduct } from "../../../store/action/pos/posAllProductAction";
import { addToast } from "../../../store/action/toastAction";

const AdjustmentTableBody = (props) => {
    const {
        singleProduct,
        index,
        updateProducts,
        setUpdateProducts,
        products,
    } = props;
    const dispatch = useDispatch();

    const onDeleteCartItem = (id) => {
        const newProduct = updateProducts.filter((item) => item.id !== id);
        setUpdateProducts(newProduct);
        dispatch(addToast({ text: getFormattedMessage("item.deleted.success.message") }));
    };

    const getAvailableStock = () => {
        const matched = products?.find ? products.find((pro) => pro.id === singleProduct.id) : null;
        const stockQty = matched?.attributes?.stock?.quantity;
        if (typeof stockQty === "number") return stockQty;
        if (typeof singleProduct.stock === "number") return singleProduct.stock;
        if (Array.isArray(singleProduct.stock) && singleProduct.stock.length > 0 && singleProduct.stock[0]?.quantity !== undefined) {
            return Number(singleProduct.stock[0].quantity) || 0;
        }
        return null;
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
        const numericVal = Number(value);
        const resolvedStock = getAvailableStock();
        setUpdateProducts((updateProducts) =>
            updateProducts.map((item) => {
                if (item.id === singleProduct.id) {
                    return item.adjustMethod === 2 && resolvedStock !== null
                        ? {
                              ...item,
                              quantity:
                                  resolvedStock < numericVal
                                      ? resolvedStock
                                      : numericVal,
                          }
                        : { ...item, quantity: numericVal };
                } else {
                    return item;
                }
            })
        );
    };

    const handleIncrement = () => {
        const resolvedStock = getAvailableStock();
        setUpdateProducts((updateProducts) =>
            updateProducts.map((item) => {
                if (item.id === singleProduct.id) {
                    const currentQty = Number(item.quantity) || 0;
                    return {
                        ...item,
                        quantity:
                            item.adjustMethod === 2 && resolvedStock !== null
                                ? Math.min(resolvedStock, currentQty + 1)
                                : currentQty + 1,
                    };
                }
                return item;
            })
        );
    };

    const handleDecrement = () => {
        setUpdateProducts((updateProducts) =>
            updateProducts.map((item) => {
                if (item.id === singleProduct.id) {
                    const currentQty = Number(item.quantity) || 0;
                    return {
                        ...item,
                        quantity: currentQty > 0 ? currentQty - 1 : 0,
                    };
                }
                return item;
            })
        );
    };

    const onMethodChange = (obj) => {
        const newMethod = Number(obj.value);
        const resolvedStock = getAvailableStock();
        setUpdateProducts((prevProducts) =>
            prevProducts.map((item) => {
                if (item.id === singleProduct.id) {
                    let nextQty = Number(item.quantity) || 0;
                    if (newMethod === 2 && resolvedStock !== null && nextQty > resolvedStock) {
                        nextQty = resolvedStock;
                    }
                    return {
                        ...item,
                        adjustMethod: newMethod,
                        quantity: nextQty,
                    };
                }
                return item;
            })
        );
    };

    const adjustmentOptions = getFormattedOptions(AdjustmentType);

    return (
        <tr key={index} className="align-middle">
            <td className="word-break-all">
                <h4 className="fs-6 mb-0">{singleProduct.name}</h4>
            </td>
            <td className="word-break-all">
                <h4 className="fs-6 mb-0">{singleProduct.code}</h4>
            </td>
            <td>
                {Array.isArray(singleProduct.stock) ? (
                    singleProduct.stock.length >= 1 ? (
                        singleProduct.stock.map((item, stockIdx) => {
                            return (
                                <span key={stockIdx} className="badge bg-light-warning">
                                    <span>
                                        {item?.quantity ?? 0}&nbsp;
                                        {singleProduct.short_name}
                                    </span>
                                </span>
                            );
                        })
                    ) : (
                        <span className="badge bg-light-danger">
                            <span>0&nbsp;{singleProduct.short_name}</span>
                        </span>
                    )
                ) : typeof singleProduct.stock === "number" ? (
                    <span className="badge bg-light-warning">
                        <span>
                            {singleProduct.stock}&nbsp;
                            {singleProduct.short_name}
                        </span>
                    </span>
                ) : (
                    <span className="badge bg-light-danger">
                        <span>N/A</span>
                    </span>
                )}
            </td>
            <td>
                <div className="custom-qty">
                    <InputGroup className="flex-nowrap">
                        <InputGroup.Text
                            className="btn btn-primary btn-sm px-4 px-4 pt-2"
                            onClick={(e) => handleDecrement(e)}
                        >
                            -
                        </InputGroup.Text>
                        <Form.Control
                            aria-label="Product Quantity"
                            onKeyPress={(event) => decimalValidate(event)}
                            className="text-center px-0 py-2 rounded-0 hide-arrow"
                            value={singleProduct.quantity || 0}
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
            <td style={{ minWidth: "182px" }}>
                <ReactSelect
                    name="AdjustmentType"
                    multiLanguageOption={adjustmentOptions}
                    onChange={onMethodChange}
                    defaultValue={
                        singleProduct.isEdit
                            ? singleProduct.adjustMethod === 1
                                ? {
                                      value: adjustmentOptions[0]?.id,
                                      label: adjustmentOptions[0]?.name,
                                  }
                                : {
                                      value: adjustmentOptions[1]?.id,
                                      label: adjustmentOptions[1]?.name,
                                  }
                            : {
                                  value: adjustmentOptions[0]?.id,
                                  label: adjustmentOptions[0]?.name,
                              }
                    }
                    isRequired
                />
            </td>
            <td className="text-center">
                <button className="btn px-2 text-danger fs-3">
                    <FontAwesomeIcon
                        icon={faTrash}
                        onClick={() => onDeleteCartItem(singleProduct.id)}
                    />
                </button>
            </td>
        </tr>
    );
};

const mapStateToProps = (state) => {
    const { productSales, products, posAllProducts } = state;
    return { productSales, products, posAllProducts };
};

export default connect(mapStateToProps, {
    productSalesDropdown,
    posAllProduct,
})(AdjustmentTableBody);
