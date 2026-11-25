import React from "react";
import { render, screen, fireEvent, waitFor, act } from "@testing-library/react";
import "@testing-library/jest-dom";
import { Provider } from "react-redux";
import { applyMiddleware, createStore } from "redux";
import thunk from "redux-thunk";
import { IntlProvider } from "react-intl";

import apiConfig from "../config/apiConfig";
import { constants, discountType, toastType } from "../constants";
import {
    calculateCartTotalAmount,
    calculateCartTotalTaxAmount,
    calculateSubTotal,
} from "../shared/calculation/calculation";
import { calculateMainAmounts } from "../shared/sharedMethod";
import ProductMainCalculation from "../components/sales/ProductMainCalculation";
import {
    fetchSalesReturn,
    fetchSaleReturn,
    addSaleReturn,
    editSaleReturn,
    deleteSaleReturn,
} from "../store/action/salesReturnAction";
import {
    fetchPurchasesReturn,
    fetchPurchaseReturn,
    addPurchaseReturn,
    editPurchaseReturn,
    deletePurchaseReturn,
} from "../store/action/purchaseReturnAction";
import DeleteSaleReturn from "../components/saleReturn/DeleteSaleReturn";
import DeletePurchaseReturn from "../components/purchaseReturn/DeletePurchaseReturn";

jest.mock("../config/apiConfig", () => ({
    get: jest.fn(),
    post: jest.fn(),
    put: jest.fn(),
    patch: jest.fn(),
    delete: jest.fn(),
}));

jest.mock("../assets/images/remove.png", () => "remove.png");

const buildCartItem = (unitPrice, quantity) => ({
    fix_net_unit: unitPrice,
    discount_type: 2,
    discount_value: 0,
    discount_amount: 0,
    tax_type: 1,
    tax_value: 0,
    tax_amount: 0,
    quantity,
    sub_total: Number((unitPrice * quantity).toFixed(2)),
});

const makeReduxStore = (reducerImpl) =>
    createStore(
        reducerImpl ||
            ((state = { isSaving: false }, action) => state),
        applyMiddleware(thunk)
    );

const silentIntlError = () => {};

describe("Phase C2 Batch 3B-1 — Order Tax & Main Calculation Helpers", () => {
    test("calculateCartTotalTaxAmount computes order tax once without multiplying by last item quantity", () => {
        const carts = [
            buildCartItem(50, 1), // sub_total = 50
            buildCartItem(25, 3), // sub_total = 75 (last item qty = 3)
        ];
        const inputValue = {
            tax_rate: "10",
            discount: "25.00",
            shipping: "5.00",
        };

        expect(calculateSubTotal(carts)).toBe(125);
        // (125 - 25) * 10% = 10.00 (previously returned 30.00 due to * 3)
        expect(calculateCartTotalTaxAmount(carts, inputValue)).toBe("10.00");
        // Matches grand total composition: (125 - 25) + 10 + 5 = 115.00
        expect(calculateCartTotalAmount(carts, inputValue)).toBe("115.00");
    });

    test("calculateCartTotalTaxAmount returns 0.00 cleanly for zero/empty carts or discount >= subtotal", () => {
        expect(calculateCartTotalTaxAmount([], { tax_rate: "10", discount: "0" })).toBe("0.00");
        expect(
            calculateCartTotalTaxAmount([buildCartItem(10, 2)], {
                tax_rate: "10",
                discount: "50",
            })
        ).toBe("0.00");
    });

    test("calculateMainAmounts supports flat discount forms (returns/purchases/transfers) and preserves SalesForm percentage & fixed modes", () => {
        const carts = [buildCartItem(100, 2)]; // subTotal = 200

        // 1. Flat discount form (SaleReturnForm / PurchaseReturnForm / PurchaseForm / TransferForm)
        const flatResult = calculateMainAmounts(carts, {
            discount: "20.00",
            tax_rate: "10",
        });
        expect(flatResult.subTotal).toBe(200);
        expect(flatResult.discountAmount).toBe(20);
        expect(flatResult.totalAmountAfterDiscount).toBe(180);
        expect(flatResult.taxCal).toBe("18.00");

        // 2. SalesForm PERCENTAGE mode
        const pctResult = calculateMainAmounts(carts, {
            discount_type: discountType.PERCENTAGE,
            discount_value: "15",
            discount: "0",
            tax_rate: "10",
        });
        expect(pctResult.discountAmount).toBe(30);
        expect(pctResult.totalAmountAfterDiscount).toBe(170);
        expect(pctResult.taxCal).toBe("17.00");

        // 3. SalesForm FIXED mode (authoritative discount_value wins over stale discount field)
        const fixedResult = calculateMainAmounts(carts, {
            discount_type: discountType.FIXED,
            discount_value: "12.50",
            discount: "99.00",
            tax_rate: "10",
        });
        expect(fixedResult.discountAmount).toBe(12.5);
        expect(fixedResult.totalAmountAfterDiscount).toBe(187.5);
        expect(fixedResult.taxCal).toBe("18.75");

        // 4. SalesForm cleared discount_value ("") stays 0 instead of falling back to stale discount
        const clearedResult = calculateMainAmounts(carts, {
            discount_type: discountType.FIXED,
            discount_value: "",
            discount: "25.00",
            tax_rate: "10",
        });
        expect(clearedResult.discountAmount).toBe(0);
        expect(clearedResult.taxCal).toBe("20.00");
    });

    test("ProductMainCalculation displays accurate flat discount, order tax, and grand total for return workflows", () => {
        const carts = [buildCartItem(50, 2)]; // subTotal = 100
        render(
            <IntlProvider locale="en" messages={{}} onError={silentIntlError}>
                <ProductMainCalculation
                    inputValues={{
                        discount: "20.00",
                        tax_rate: "10",
                        shipping: "5.00",
                    }}
                    updateProducts={carts}
                    frontSetting={{ value: { currency_symbol: "$" } }}
                    allConfigData={{ is_currency_right: "false" }}
                />
            </IntlProvider>
        );

        expect(screen.getByText(/\$ 20\.00|\$20\.00/)).toBeInTheDocument();
        expect(screen.getByText(/\$ 8\.00|\$8\.00/)).toBeInTheDocument();
        // (100 - 20) + 8 + 5 = 93.00
        expect(screen.getByText(/\$ 93\.00|\$93\.00/)).toBeInTheDocument();
    });
});

describe("Phase C2 Batch 3B-1 — Return Redux Thunks (Awaitability & Error Loader Cleanup)", () => {
    beforeEach(() => {
        jest.clearAllMocks();
    });

    test("fetchSalesReturn and fetchSaleReturn reset isLoading=false on API failure and return awaitable booleans", async () => {
        const dispatch = jest.fn();

        apiConfig.get.mockRejectedValueOnce({
            response: { status: 500, data: { message: "Server down" } },
        });
        const listOk = await fetchSalesReturn({ page: 1 }, true)(dispatch);
        expect(listOk).toBe(false);
        expect(dispatch).toHaveBeenCalledWith({
            type: constants.IS_LOADING,
            payload: true,
        });
        expect(dispatch).toHaveBeenCalledWith({
            type: constants.IS_LOADING,
            payload: false,
        });
        expect(dispatch).toHaveBeenCalledWith(
            expect.objectContaining({
                payload: expect.objectContaining({
                    text: "Server down",
                    type: toastType.ERROR,
                }),
            })
        );

        jest.clearAllMocks();
        apiConfig.get.mockRejectedValueOnce(new Error("Network timeout"));
        const singleOk = await fetchSaleReturn(12, false, true)(dispatch);
        expect(singleOk).toBe(false);
        expect(dispatch).toHaveBeenCalledWith({
            type: constants.IS_LOADING,
            payload: false,
        });

        jest.clearAllMocks();
        apiConfig.get.mockResolvedValueOnce({
            data: { data: [{ id: 1 }], meta: { total: 1 } },
        });
        const ok = await fetchSalesReturn({}, true)(dispatch);
        expect(ok).toBe(true);
        expect(dispatch).toHaveBeenCalledWith({
            type: constants.IS_LOADING,
            payload: false,
        });
    });

    test("fetchPurchasesReturn and fetchPurchaseReturn await apiConfig.get, return boolean status, and always clear loader on error", async () => {
        const dispatch = jest.fn();

        apiConfig.get.mockRejectedValueOnce({
            response: { status: 403, data: { message: "Forbidden" } },
        });
        const prListOk = await fetchPurchasesReturn({ page: 2 }, true)(dispatch);
        expect(prListOk).toBe(false);
        expect(dispatch).toHaveBeenCalledWith({
            type: constants.IS_LOADING,
            payload: false,
        });

        jest.clearAllMocks();
        apiConfig.get.mockRejectedValueOnce({
            response: { status: 404, data: { message: "Not found" } },
        });
        const prOneOk = await fetchPurchaseReturn(44, false, true)(dispatch);
        expect(prOneOk).toBe(false);
        expect(dispatch).toHaveBeenCalledWith({
            type: constants.IS_LOADING,
            payload: false,
        });

        jest.clearAllMocks();
        apiConfig.get.mockResolvedValueOnce({
            data: { data: { id: 44 } },
        });
        const ok = await fetchPurchaseReturn(44, true, true)(dispatch);
        expect(ok).toBe(true);
        expect(dispatch).toHaveBeenCalledWith({
            type: constants.IS_LOADING,
            payload: false,
        });
    });

    test("add/edit/delete return thunks return awaitable true/false, preserve navigate on success only, and clear saving state", async () => {
        const dispatch = jest.fn();
        const navigate = jest.fn();

        // addSaleReturn 422 failure
        apiConfig.post.mockRejectedValueOnce({
            response: { status: 422, data: { message: "Exceeds sold qty" } },
        });
        expect(await addSaleReturn({ status: 1 }, navigate)(dispatch)).toBe(false);
        expect(navigate).not.toHaveBeenCalled();
        expect(dispatch).toHaveBeenCalledWith({ type: "SET_SAVING", payload: true });
        expect(dispatch).toHaveBeenCalledWith({ type: "SET_SAVING", payload: false });

        // editSaleReturn 200 success
        jest.clearAllMocks();
        apiConfig.patch.mockResolvedValueOnce({ data: { data: { id: 9 } } });
        expect(await editSaleReturn(9, { status: 1 }, navigate)(dispatch)).toBe(true);
        expect(navigate).toHaveBeenCalledWith("/app/user/sale-return");
        expect(dispatch).toHaveBeenCalledWith({ type: "SET_SAVING", payload: false });

        // addPurchaseReturn 422 failure
        jest.clearAllMocks();
        apiConfig.post.mockRejectedValueOnce({
            response: { status: 422, data: { message: "Invalid status" } },
        });
        expect(await addPurchaseReturn({ status: 3 }, navigate)(dispatch)).toBe(false);
        expect(navigate).not.toHaveBeenCalled();
        expect(dispatch).toHaveBeenCalledWith({ type: "SET_SAVING", payload: false });

        // editPurchaseReturn 200 success
        jest.clearAllMocks();
        apiConfig.put.mockResolvedValueOnce({ data: { data: { id: 15 } } });
        expect(await editPurchaseReturn(15, { status: 2 }, navigate)(dispatch)).toBe(true);
        expect(navigate).toHaveBeenCalledWith("/app/user/purchase-return");
        expect(dispatch).toHaveBeenCalledWith({ type: "SET_SAVING", payload: false });

        // deleteSaleReturn & deletePurchaseReturn awaitable results
        jest.clearAllMocks();
        apiConfig.delete.mockRejectedValueOnce({
            response: { status: 422, data: { message: "Stock would go negative" } },
        });
        expect(await deleteSaleReturn(10)(dispatch)).toBe(false);

        apiConfig.delete.mockResolvedValueOnce({ data: {} });
        expect(await deletePurchaseReturn(20)(dispatch)).toBe(true);
    });
});

describe("Phase C2 Batch 3B-1 — Async DeleteSaleReturn & DeletePurchaseReturn Modals", () => {
    beforeEach(() => {
        jest.clearAllMocks();
    });

    test("DeleteSaleReturn keeps modal open on 422 rejection, blocks double-clicks while in-flight, and closes only on success", async () => {
        let resolveDelete;
        apiConfig.delete.mockImplementationOnce(
            () =>
                new Promise((resolve) => {
                    resolveDelete = resolve;
                })
        );

        const onClickDeleteModel = jest.fn();
        const store = makeReduxStore();

        render(
            <IntlProvider locale="en" messages={{}} onError={silentIntlError}>
                <Provider store={store}>
                    <DeleteSaleReturn
                        deleteModel={true}
                        onDelete={{ id: 77 }}
                        onClickDeleteModel={onClickDeleteModel}
                    />
                </Provider>
            </IntlProvider>
        );

        const yesBtn = screen.getByText("delete-modal.yes-btn");
        fireEvent.click(yesBtn);

        // Modal must stay open synchronously while awaiting API
        expect(onClickDeleteModel).not.toHaveBeenCalled();

        // Escape & Cancel & second confirm click must not close or double-submit while in-flight
        fireEvent.keyDown(document, { keyCode: 27 });
        fireEvent.click(screen.getByText("delete-modal.no-btn"));
        fireEvent.click(screen.getByText("delete-modal.yes-btn"));
        expect(onClickDeleteModel).not.toHaveBeenCalled();
        expect(apiConfig.delete).toHaveBeenCalledTimes(1);

        // Finish with 200 success -> closes modal once
        await act(async () => {
            resolveDelete({ data: {} });
        });
        await waitFor(() => expect(onClickDeleteModel).toHaveBeenCalledWith(false));

        // Failure case keeps modal open & interactive
        jest.clearAllMocks();
        apiConfig.delete.mockRejectedValueOnce({
            response: { status: 422, data: { message: "Cannot delete return" } },
        });
        fireEvent.click(screen.getByText("delete-modal.yes-btn"));
        await waitFor(() => expect(apiConfig.delete).toHaveBeenCalledTimes(1));
        await act(async () => {});
        expect(onClickDeleteModel).not.toHaveBeenCalled();
        expect(screen.getByText("delete-modal.yes-btn")).toBeInTheDocument();
    });

    test("DeletePurchaseReturn keeps modal open on API failure and closes only after deletePurchaseReturn resolves true", async () => {
        const onClickDeleteModel = jest.fn();
        const store = makeReduxStore();

        render(
            <IntlProvider locale="en" messages={{}} onError={silentIntlError}>
                <Provider store={store}>
                    <DeletePurchaseReturn
                        deleteModel={true}
                        onDelete={{ id: 88 }}
                        onClickDeleteModel={onClickDeleteModel}
                    />
                </Provider>
            </IntlProvider>
        );

        // 1. Rejection -> stays open
        apiConfig.delete.mockRejectedValueOnce({
            response: { status: 422, data: { message: "Cannot delete purchase return" } },
        });
        fireEvent.click(screen.getByText("delete-modal.yes-btn"));
        await waitFor(() => expect(apiConfig.delete).toHaveBeenCalledTimes(1));
        await act(async () => {});
        expect(onClickDeleteModel).not.toHaveBeenCalled();
        expect(screen.getByText("delete-modal.yes-btn")).toBeInTheDocument();

        // 2. Retry succeeds -> closes modal
        apiConfig.delete.mockResolvedValueOnce({ data: {} });
        fireEvent.click(screen.getByText("delete-modal.yes-btn"));
        await waitFor(() => expect(onClickDeleteModel).toHaveBeenCalledWith(false));
    });
});
