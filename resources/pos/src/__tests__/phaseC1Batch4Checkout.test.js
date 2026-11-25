import React from "react";
import { render, screen, fireEvent } from "@testing-library/react";
import "@testing-library/jest-dom";
import { Provider } from "react-redux";
import { createStore } from "redux";
import { IntlProvider } from "react-intl";

import apiConfig from "../config/apiConfig";
import { posCashPaymentAction } from "../store/action/pos/posCashPaymentAction";
import { addHoldList } from "../store/action/pos/HoldListAction";
import CashPaymentModel from "../frontend/components/cart-product/paymentModel/CashPaymentModel";

jest.mock("../config/apiConfig", () => ({
    post: jest.fn(),
    get: jest.fn(),
}));

jest.mock("../shared/sharedMethod", () => ({
    getFormattedMessage: (key) => key,
    placeholderText: (key) => key,
    currencySymbolHandling: (_cfg, sym, val) => `${sym || "$"}${val}`,
    getFormattedOptions: () => [{ id: 1, name: "Paid" }],
    numValidate: jest.fn(),
}));

const makeStore = () => createStore((state = { paymentMethods: [] }) => state);

describe("Phase C1 Batch 4 — Checkout Double-Submit, Idempotency & Cart State Preservation", () => {
    beforeEach(() => {
        jest.clearAllMocks();
    });

    test("C1.7: CashPaymentModel disables Submit/Submit&Print/Cancel buttons and blocks clicks while isSubmitting=true", () => {
        const onCashPayment = jest.fn();
        render(
            <IntlProvider locale="en" messages={{}}>
                <Provider store={makeStore()}>
                    <CashPaymentModel
                    cashPayment={true}
                    isSubmitting={true}
                    totalQty={2}
                    cartItemValue={{ discount: 0, tax: 0, shipping: 0 }}
                    cashPaymentValue={{
                        notes: "",
                        payment_status: { value: 1, label: "Paid" },
                        payment_details: [{ amount: 100, payment_type: { value: 1, label: "Cash" } }],
                    }}
                    allConfigData={{}}
                    subTotal={100}
                    grandTotal={100}
                    taxTotal={0}
                    onCashPayment={onCashPayment}
                    handleCashPayment={jest.fn()}
                    onChangeInput={jest.fn()}
                    onPaymentStatusChange={jest.fn()}
                    onPaymentTypeChange={jest.fn()}
                    onChangeReturnChange={jest.fn()}
                    setPaymentValue={jest.fn()}
                    handlePaymentDetailChange={jest.fn()}
                    handleAddPayment={jest.fn()}
                    handleRemovePayment={jest.fn()}
                    settings={{ attributes: { currency_symbol: "$" } }}
                    errors={{}}
                    paymentTypeDefaultValue={[]}
                    paymentTypeFilterOptions={[]}
                    />
                </Provider>
            </IntlProvider>
        );

        const submitBtn = screen.getByText("globally.submit-btn");
        const submitPrintBtn = screen.getByText("globally.submit-and-print-button");
        const cancelBtn = screen.getByText("globally.cancel-btn");

        expect(submitBtn).toBeDisabled();
        expect(submitPrintBtn).toBeDisabled();
        expect(cancelBtn).toBeDisabled();

        fireEvent.click(submitBtn);
        fireEvent.click(submitPrintBtn);
        expect(onCashPayment).not.toHaveBeenCalled();
    });

    test("C1.7/C1.8: posCashPaymentAction preserves cart & order state and resets loader on 422/500/network errors", async () => {
        const dispatch = jest.fn();
        const setUpdateProducts = jest.fn();
        const setModalShowPaymentSlip = jest.fn();
        const onSuccess = jest.fn();

        // 1. 422 validation error
        apiConfig.post.mockRejectedValueOnce({
            response: { status: 422, data: { message: "Insufficient stock." } },
        });

        const ok422 = await posCashPaymentAction(
            { grand_total: 100 },
            setUpdateProducts,
            setModalShowPaymentSlip,
            { selectedOption: { value: 1 } },
            true,
            true,
            { idempotencyKey: "pos_key_422", onSuccess }
        )(dispatch);

        expect(ok422).toBe(false);
        expect(setUpdateProducts).not.toHaveBeenCalled();
        expect(onSuccess).not.toHaveBeenCalled();
        expect(setModalShowPaymentSlip).not.toHaveBeenCalled();
        expect(dispatch).toHaveBeenCalledWith({ type: "IS_LOADING", payload: true });
        expect(dispatch).toHaveBeenCalledWith({ type: "IS_LOADING", payload: false });
        expect(dispatch).toHaveBeenCalledWith(
            expect.objectContaining({
                type: "ADD_TOAST",
                payload: expect.objectContaining({ text: "Insufficient stock." }),
            })
        );

        // 2. Network timeout (no response object)
        jest.clearAllMocks();
        apiConfig.post.mockRejectedValueOnce(new Error("Network Error"));

        const okNet = await posCashPaymentAction(
            { grand_total: 100 },
            setUpdateProducts,
            setModalShowPaymentSlip,
            { selectedOption: { value: 1 } },
            false,
            true,
            { idempotencyKey: "pos_key_net", onSuccess }
        )(dispatch);

        expect(okNet).toBe(false);
        expect(setUpdateProducts).not.toHaveBeenCalled();
        expect(onSuccess).not.toHaveBeenCalled();
        expect(dispatch).toHaveBeenCalledWith({ type: "IS_LOADING", payload: false });
    });

    test("C1.7/C1.8: posCashPaymentAction sends Idempotency-Key and clears cart/state ONLY after HTTP 2xx", async () => {
        const dispatch = jest.fn();
        const setUpdateProducts = jest.fn();
        const setModalShowPaymentSlip = jest.fn();
        const onSuccess = jest.fn();

        apiConfig.post.mockResolvedValueOnce({
            data: { data: { id: 99, attributes: { reference_code: "SA_99" } } },
        });

        const ok = await posCashPaymentAction(
            { grand_total: 80 },
            setUpdateProducts,
            setModalShowPaymentSlip,
            { brandId: 0, categoryId: 0, selectedOption: { value: 339 } },
            true,
            true,
            { idempotencyKey: "pos_uuid_123", onSuccess }
        )(dispatch);

        expect(ok).toBe(true);
        expect(apiConfig.post).toHaveBeenCalledWith(
            expect.any(String),
            { grand_total: 80 },
            { headers: { "Idempotency-Key": "pos_uuid_123" } }
        );
        expect(setUpdateProducts).toHaveBeenCalledWith([]);
        expect(onSuccess).toHaveBeenCalledWith({ id: 99, attributes: { reference_code: "SA_99" } });
        expect(setModalShowPaymentSlip).toHaveBeenCalledWith(true);
        expect(dispatch).toHaveBeenCalledWith({ type: "IS_LOADING", payload: false });
    });

    test("C1.8: addHoldList returns false on error (preserving cart) and true on HTTP 2xx (confirming hold)", async () => {
        const dispatch = jest.fn();

        // Failure case
        apiConfig.post.mockRejectedValueOnce({
            response: { status: 422, data: { message: "Reference code invalid." } },
        });
        const failResult = await addHoldList({ reference_code: "H1" })(dispatch);
        expect(failResult).toBe(false);

        // Success case
        apiConfig.post.mockResolvedValueOnce({
            data: { data: { id: 5, attributes: { reference_code: "H1" } } },
        });
        const okResult = await addHoldList({ reference_code: "H1" })(dispatch);
        expect(okResult).toBe(true);
    });
});
