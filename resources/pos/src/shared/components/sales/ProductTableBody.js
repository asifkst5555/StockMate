import React, { useEffect, useState } from "react";
import { InputGroup } from "react-bootstrap-v5";
import { connect, useDispatch } from "react-redux";
import Form from "react-bootstrap/Form";
import {
    taxAmountMultiply,
    discountAmountMultiply,
    subTotalCount,
    amountBeforeTax,
} from "../../calculation/calculation";
import ProductModal from "./ProductModal";
import { productSalesDropdown } from "../../../store/action/productSaleUnitAction";
import {
    currencySymbolHandling,
    decimalValidate,
    getFormattedMessage,
} from "../../sharedMethod";
import { FontAwesomeIcon } from "@fortawesome/react-fontawesome";
import { faPencil, faTrash } from "@fortawesome/free-solid-svg-icons";
import { addToast } from "../../../store/action/toastAction";
import { toastType } from "../../../constants";

const ProductTableBody = (props) => {
    const {
        singleProduct,
        index,
        updateProducts,
        setUpdateProducts,
        productSales,
        productSalesDropdown,
        updateCost,
        updateDiscount,
        updateTax,
        updateSubTotal,
        updateSaleUnit,
        frontSetting,
        allConfigData,
    } = props;
    const [isShowModal, setIsShowModal] = useState(false);
    const [updateProductData, setUpdateProductData] = useState([]);
    const dispatch = useDispatch();

    useEffect(() => {
        singleProduct.newItem !== "" &&
            productSalesDropdown(singleProduct.product_unit);
    }, [updateProductData, singleProduct.sale_unit]);

    const withUpdatedQuantity = (item, newQuantity) => {
        const updated = { ...item, quantity: newQuantity };
        return {
            ...updated,
            sub_total: Number(subTotalCount(updated)),
        };
    };

    const onProductUpdateInCart = (item) => {
        setUpdateProductData(item);
    };

    const onDeleteCartItem = (id) => {
        const newProduct = updateProducts.filter((item) => item.id !== id);
        setUpdateProducts(newProduct);
        dispatch(addToast({ text: getFormattedMessage("item.deleted.success.message") }));
    };

    const handleIncrement = () => {
        singleProduct.isSaleReturn || singleProduct.isSaleReturnEdit
            ? setUpdateProducts((updateProducts) =>
                  updateProducts.map((item) => {
                      if (item.id === singleProduct.id) {
                          const currentQty = Number(item.quantity) || 0;
                          const soldQty = Number(item.sold_quantity) || 0;
                          if (currentQty >= soldQty) {
                              dispatch(
                                  addToast({
                                      text: getFormattedMessage(
                                          "sale-return.product-qty.validate.message"
                                      ),
                                      type: toastType.ERROR,
                                  })
                              );
                              return item;
                          } else {
                              const newQuantity = Math.min(
                                  soldQty,
                                  Number((currentQty + 1).toFixed(2))
                              );
                              return withUpdatedQuantity(item, newQuantity);
                          }
                      } else {
                          return item;
                      }
                  })
              )
            : setUpdateProducts((updateProducts) =>
                  updateProducts.map((item) => {
                      if (item.id === singleProduct.id) {
                          const newQuantity = Number(
                              ((Number(item.quantity) || 0) + 1).toFixed(2)
                          );
                          if (
                              item.quantity_limit &&
                              newQuantity > item.quantity_limit
                          ) {
                              dispatch(
                                  addToast({
                                      text: getFormattedMessage(
                                          "sale.product-qty.limit.validate.message"
                                      ),
                                      type: toastType.ERROR,
                                  })
                              );
                              return { ...item };
                          }
                          return withUpdatedQuantity(item, newQuantity);
                      } else {
                          return item;
                      }
                  })
              );
    };

    const handleDecrement = () => {
        const currentQty = Number(singleProduct.quantity) || 0;
        const minQuantity =
            singleProduct.isSaleReturn || singleProduct.isSaleReturnEdit ? 0 : 1;
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

        singleProduct.isSaleReturn || singleProduct.isSaleReturnEdit
            ? setUpdateProducts((updateProducts) =>
                  updateProducts.map((item) => {
                      if (item.id === singleProduct.id) {
                          if (
                              item.sold_quantity !== undefined &&
                              item.sold_quantity !== null &&
                              Number(item.sold_quantity) < parsedQty
                          ) {
                              dispatch(
                                  addToast({
                                      text: getFormattedMessage(
                                          "sale-return.product-qty.validate.message"
                                      ),
                                      type: toastType.ERROR,
                                  })
                              );
                              return withUpdatedQuantity(
                                  item,
                                  Number(item.sold_quantity)
                              );
                          } else {
                              return withUpdatedQuantity(item, parsedQty);
                          }
                      } else {
                          return item;
                      }
                  })
              )
            : setUpdateProducts((updateProducts) =>
                  updateProducts.map((item) =>
                      item.id === singleProduct.id
                          ? withUpdatedQuantity(item, parsedQty)
                          : item
                  )
              );
    };

    const onClickShowProductModal = () => {
        setIsShowModal(true);
        productSalesDropdown(singleProduct.product_unit);
    };

    return (
        <>
            <tr key={index} className="align-middle text-nowrap">
                <td>
                    <h4 className="product-name">{singleProduct.name}</h4>
                    <div className="d-flex align-items-center">
                        <span className="badge bg-light-success">
                            <span>{singleProduct.code}</span>
                        </span>
                        {singleProduct.isSaleReturn === true ||
                        singleProduct.isSaleReturnEdit === true ? null : (
                            <span className="badge bg-light-primary p-1 ms-1">
                                <FontAwesomeIcon
                                    icon={faPencil}
                                    onClick={(e) => onClickShowProductModal(e)}
                                    style={{ cursor: "pointer" }}
                                />
                            </span>
                        )}
                    </div>
                </td>
                <td>
                    {currencySymbolHandling(
                        allConfigData,
                        frontSetting.value &&
                            frontSetting.value.currency_symbol,
                        amountBeforeTax(singleProduct).toFixed(2)
                    )}
                </td>
                <td>
                    {singleProduct.isEdit ? (
                        singleProduct.stock.length >= 1 ? (
                            singleProduct.stock.map((item) => {
                                return (
                                    <span className="badge bg-light-warning">
                                        <span>
                                            {item.quantity}&nbsp;
                                            {singleProduct?.short_name}
                                        </span>
                                    </span>
                                );
                            })
                        ) : singleProduct.stock === "" ? (
                            <span className="badge bg-light-warning">
                                <span>
                                    {singleProduct.sold_quantity}&nbsp;
                                    {singleProduct?.short_name}
                                </span>
                            </span>
                        ) : null
                    ) : singleProduct.stock >= 0 ? (
                        <span className="badge bg-light-warning">
                            <span>
                                {singleProduct.stock}&nbsp;
                                {singleProduct?.short_name}
                            </span>
                        </span>
                    ) : null}
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
                                value={singleProduct.quantity}
                                type="number"
                                step={0.01}
                                min={0.0}
                                onChange={(e) => handleChange(e,singleProduct)}
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
                {singleProduct.isSaleReturn ||
                singleProduct.isSaleReturnEdit ? null : (
                    <td className="text-start">
                        <button className="btn px-2 text-danger fs-3">
                            <FontAwesomeIcon
                                icon={faTrash}
                                onClick={() =>
                                    onDeleteCartItem(singleProduct.id)
                                }
                            />
                        </button>
                    </td>
                )}
            </tr>
            {isShowModal && (
                <ProductModal
                    product={singleProduct}
                    isShowModal={isShowModal}
                    frontSetting={frontSetting}
                    updateSubTotal={updateSubTotal}
                    setIsShowModal={setIsShowModal}
                    updateCost={updateCost}
                    updateDiscount={updateDiscount}
                    updateTax={updateTax}
                    productSales={productSales}
                    updateSaleUnit={updateSaleUnit}
                    onProductUpdateInCart={onProductUpdateInCart}
                />
            )}
        </>
    );
};

const mapStateToProps = (state) => {
    const { productSales, allConfigData } = state;
    return { productSales, allConfigData };
};

export default connect(mapStateToProps, { productSalesDropdown })(
    ProductTableBody
);
