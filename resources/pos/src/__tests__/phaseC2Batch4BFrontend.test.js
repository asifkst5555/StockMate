import React from "react";
import { render, screen, fireEvent, waitFor, act } from "@testing-library/react";
import "@testing-library/jest-dom";
import { Provider } from "react-redux";
import { applyMiddleware, createStore } from "redux";
import thunk from "redux-thunk";
import { IntlProvider } from "react-intl";
import _ from "lodash";

global._ = _;

import apiConfig from "../config/apiConfig";
import { adjustMentActionType, transferActionType, toastType } from "../constants";
import adjustMentReducer from "../store/reducers/adjustMentReducer";
import transfersReducer from "../store/reducers/transfersReducer";
import requestParam from "../shared/requestParam";
import {
    fetchAdjustments,
    fetchAdjustment,
    addAdjustment,
    editAdjustment,
    deleteAdjustment,
} from "../store/action/adjustMentAction";
import {
    fetchTransfers,
    fetchTransfer,
    addTransfer,
    editTransfer,
    deletetransfer,
} from "../store/action/transfersAction";
import DeleteSaleAdjustMents from "../components/adjustments/DeleteSaleAdjustMents";
import DeleteTransfer from "../components/transfers/DeleteTransfer";
import AdjustmentTableBody from "../shared/components/adjustments/AdjustmentTableBody";
import ProductModal from "../shared/components/transfers/ProductModal";

jest.mock("../config/apiConfig", () => ({
    get: jest.fn(),
    post: jest.fn(),
    put: jest.fn(),
    patch: jest.fn(),
    delete: jest.fn(),
}));

const makeReduxStore = (reducerImpl) =>
    createStore(
        reducerImpl ||
            ((state = { isSaving: false, toast: [], isOptionDisabled: false }, action) => {
                if (action.type === "SET_SAVING_BUTTON") {
                    return { ...state, isSaving: action.payload };
                }
                if (action.type === "ADD_TOAST") {
                    return { ...state, toast: [...(state.toast || []), action.payload] };
                }
                if (action.type === "DISABLE_OPTION") {
                    return { ...state, isOptionDisabled: action.payload };
                }
                return state;
            }),
        applyMiddleware(thunk)
    );

const silentIntlError = () => {};

describe("Phase C2 Batch 4B — Reducer Direct Edit & Collection Normalization", () => {
    test("adjustMentReducer handles array state and single-object direct edit state safely", () => {
        // Initial / empty state
        expect(adjustMentReducer(undefined, {})).toEqual({});

        // FETCH_ADJUSTMENTS with array payload
        const arrayPayload = [{ id: 10, attributes: { reference_code: "ADJ-001" } }];
        const stateArray = adjustMentReducer([], {
            type: adjustMentActionType.FETCH_ADJUSTMENTS,
            payload: arrayPayload,
        });
        expect(stateArray).toEqual(arrayPayload);

        // EDIT_ADJUSTMENTS on array state maps item
        const updatedItem = { id: 10, attributes: { reference_code: "ADJ-001-UPDATED" } };
        const mappedArray = adjustMentReducer(stateArray, {
            type: adjustMentActionType.EDIT_ADJUSTMENTS,
            payload: updatedItem,
        });
        expect(mappedArray).toEqual([updatedItem]);

        // FETCH_ADJUSTMENT loads single object
        const singleObj = { id: 10, attributes: { reference_code: "ADJ-001-SINGLE" } };
        const singleState = adjustMentReducer([], {
            type: adjustMentActionType.FETCH_ADJUSTMENT,
            payload: singleObj,
        });
        expect(singleState).toEqual(singleObj);

        // EDIT_ADJUSTMENTS on direct single-object state replaces safely without crashing on .map
        const directEdited = adjustMentReducer(singleState, {
            type: adjustMentActionType.EDIT_ADJUSTMENTS,
            payload: { ...singleObj, notes: "edited" },
        });
        expect(directEdited.notes).toBe("edited");

        // DELETE_ADJUSTMENT on array state filters
        const postDeleteArray = adjustMentReducer(mappedArray, {
            type: adjustMentActionType.DELETE_ADJUSTMENT,
            payload: 10,
        });
        expect(postDeleteArray).toEqual([]);

        // DELETE_ADJUSTMENT on single-object state clears safely
        const postDeleteSingle = adjustMentReducer(singleObj, {
            type: adjustMentActionType.DELETE_ADJUSTMENT,
            payload: 10,
        });
        expect(postDeleteSingle).toEqual({});
    });

    test("transfersReducer handles array state and single-object direct edit state safely", () => {
        expect(transfersReducer(undefined, {})).toEqual({});

        const arrayPayload = [{ id: 20, attributes: { reference_code: "TR-001" } }];
        const stateArray = transfersReducer([], {
            type: transferActionType.FETCH_TRANSFERS,
            payload: arrayPayload,
        });
        expect(stateArray).toEqual(arrayPayload);

        const updatedItem = { id: 20, attributes: { reference_code: "TR-001-UPDATED" } };
        const mappedArray = transfersReducer(stateArray, {
            type: transferActionType.EDIT_TRANSFER,
            payload: updatedItem,
        });
        expect(mappedArray).toEqual([updatedItem]);

        const singleObj = { id: 20, attributes: { reference_code: "TR-001-SINGLE" } };
        const singleState = transfersReducer([], {
            type: transferActionType.FETCH_TRANSFER,
            payload: singleObj,
        });
        expect(singleState).toEqual(singleObj);

        const directEdited = transfersReducer(singleState, {
            type: transferActionType.EDIT_TRANSFER,
            payload: { ...singleObj, notes: "transfer updated" },
        });
        expect(directEdited.notes).toBe("transfer updated");

        const postDeleteArray = transfersReducer(mappedArray, {
            type: transferActionType.DELETE_TRANSFER,
            payload: 20,
        });
        expect(postDeleteArray).toEqual([]);

        const postDeleteSingle = transfersReducer(singleObj, {
            type: transferActionType.DELETE_TRANSFER,
            payload: 20,
        });
        expect(postDeleteSingle).toEqual({});
    });
});

describe("Phase C2 Batch 4B — RequestParam Warehouse & Status Serialization", () => {
    test("requestParam serializes from_warehouse_id and to_warehouse_id correctly", () => {
        const query = requestParam({
            from_warehouse_id: 1,
            to_warehouse_id: 2,
        });
        expect(query).toContain("from_warehouse_id=1");
        expect(query).toContain("to_warehouse_id=2");
    });

    test("requestParam serializes status without undefined payment parameters", () => {
        const query = requestParam({
            status: 2,
        });
        expect(query).toContain("status=2");
        expect(query).not.toContain("payment_status=undefined");
        expect(query).not.toContain("payment_type=undefined");
    });
});

describe("Phase C2 Batch 4B — Actions Awaitable Contract & Error Safety", () => {
    beforeEach(() => {
        jest.clearAllMocks();
    });

    test("adjustMentAction addAdjustment and editAdjustment return booleans and clean up saving state", async () => {
        const store = makeReduxStore();
        const navigate = jest.fn();

        apiConfig.post.mockResolvedValueOnce({
            data: { data: { id: 1 } },
        });

        const addResult = await store.dispatch(addAdjustment({ note: "test" }, navigate));
        expect(addResult).toBe(true);
        expect(navigate).toHaveBeenCalledWith("/app/user/adjustments");
        expect(store.getState().isSaving).toBe(false);

        apiConfig.patch.mockRejectedValueOnce({
            response: { data: { message: "Adjustment not allowed" } },
        });

        const editResult = await store.dispatch(editAdjustment(1, { note: "test" }, navigate));
        expect(editResult).toBe(false);
        expect(store.getState().isSaving).toBe(false);
    });

    test("transfersAction editTransfer uses transfer success message and handles errors cleanly", async () => {
        const store = makeReduxStore();
        const navigate = jest.fn();

        apiConfig.patch.mockResolvedValueOnce({
            data: { data: { id: 5 } },
        });

        const result = await store.dispatch(editTransfer(5, { status: 1 }, navigate));
        expect(result).toBe(true);
        expect(navigate).toHaveBeenCalledWith("/app/user/transfers");
        const toasts = store.getState().toast;
        expect(
            toasts.some(
                (t) =>
                    t.text === "transfer.success.edit.message" ||
                    t.text?.props?.id === "transfer.success.edit.message"
            )
        ).toBe(true);
        expect(store.getState().isSaving).toBe(false);
    });

    test("delete actions return true on success and false on API rejection", async () => {
        const store = makeReduxStore();

        apiConfig.delete.mockResolvedValueOnce({});
        const delAdjOk = await store.dispatch(deleteAdjustment(10));
        expect(delAdjOk).toBe(true);

        apiConfig.delete.mockRejectedValueOnce({
            response: { data: { message: "Cannot delete transfer" } },
        });
        const delTrFail = await store.dispatch(deletetransfer(20));
        expect(delTrFail).toBe(false);
    });
});

describe("Phase C2 Batch 4B — Async Delete Modal Protection", () => {
    beforeEach(() => {
        jest.clearAllMocks();
    });

    test("DeleteSaleAdjustMents does not close modal when deletion fails", async () => {
        apiConfig.delete.mockRejectedValueOnce({
            response: { status: 422, data: { message: "Cannot delete adjustment" } },
        });
        const onClickDeleteModel = jest.fn();
        const store = makeReduxStore();

        render(
            <Provider store={store}>
                <IntlProvider locale="en" messages={{}} onError={silentIntlError}>
                    <DeleteSaleAdjustMents
                        onDelete={{ id: 99 }}
                        deleteModel={true}
                        onClickDeleteModel={onClickDeleteModel}
                    />
                </IntlProvider>
            </Provider>
        );

        const yesBtn = screen.getByText("delete-modal.yes-btn");
        fireEvent.click(yesBtn);

        await waitFor(() => {
            expect(apiConfig.delete).toHaveBeenCalledTimes(1);
        });

        // Modal should NOT close on failure
        expect(onClickDeleteModel).not.toHaveBeenCalledWith(false);
    });

    test("DeleteTransfer closes modal only on successful deletion and blocks duplicate clicks", async () => {
        let resolveDelete;
        const deletePromise = new Promise((resolve) => {
            resolveDelete = resolve;
        });
        apiConfig.delete.mockImplementationOnce(() => deletePromise);
        const onClickDeleteModel = jest.fn();
        const store = makeReduxStore();

        render(
            <Provider store={store}>
                <IntlProvider locale="en" messages={{}} onError={silentIntlError}>
                    <DeleteTransfer
                        onDelete={{ id: 88 }}
                        deleteModel={true}
                        onClickDeleteModel={onClickDeleteModel}
                    />
                </IntlProvider>
            </Provider>
        );

        const yesBtn = screen.getByText("delete-modal.yes-btn");
        // Click once
        fireEvent.click(yesBtn);
        // Click second time while in flight
        fireEvent.click(yesBtn);

        expect(apiConfig.delete).toHaveBeenCalledTimes(1);

        // Resolve deletion successfully
        await act(async () => {
            resolveDelete({ data: {} });
        });

        await waitFor(() => {
            expect(onClickDeleteModel).toHaveBeenCalledWith(false);
        });
    });
});

describe("Phase C2 Batch 4B — AdjustmentTableBody Null Stock & Method Switching", () => {
    test("renders N/A without crashing when stock is null or undefined", () => {
        const singleProduct = {
            id: 1,
            name: "Test Product",
            code: "TP01",
            stock: null,
            short_name: "pcs",
            quantity: 2,
            adjustMethod: 1,
        };
        const store = makeReduxStore();

        render(
            <Provider store={store}>
                <IntlProvider locale="en" messages={{}} onError={silentIntlError}>
                    <table>
                        <tbody>
                            <AdjustmentTableBody
                                singleProduct={singleProduct}
                                index={0}
                                updateProducts={[singleProduct]}
                                setUpdateProducts={jest.fn()}
                                products={[]}
                            />
                        </tbody>
                    </table>
                </IntlProvider>
            </Provider>
        );

        expect(screen.getByText("N/A")).toBeInTheDocument();
        expect(screen.getByText("Test Product")).toBeInTheDocument();
    });
});

describe("Phase C2 Batch 4B — Transfer ProductModal Pricing Synchronization", () => {
    test("onSaveDetailModal synchronizes product_cost, product_price, fix_net_unit and net_unit_price immutably", () => {
        const initialProduct = {
            id: 101,
            name: "Transfer Item",
            product_cost: 50,
            product_price: 50,
            net_unit_cost: 50,
            fix_net_unit: 50,
            tax_type: 1,
            tax_value: 0,
            tax_amount: 0,
            discount_type: 2,
            discount_value: 0,
            discount_amount: 0,
            purchase_unit: 1,
            quantity: 2,
            sub_total: 100,
        };

        const onProductUpdateInCart = jest.fn();
        const handleClose = jest.fn();
        const updateCost = jest.fn();
        const updateTax = jest.fn();
        const updateDiscount = jest.fn();
        const updateSubTotal = jest.fn();
        const updatePurchaseUnit = jest.fn();
        const store = makeReduxStore();

        render(
            <Provider store={store}>
                <IntlProvider locale="en" messages={{}} onError={silentIntlError}>
                    <ProductModal
                        isOpen={true}
                        title="Edit Transfer Product"
                        id={101}
                        modalId={101}
                        product={initialProduct}
                        handleClose={handleClose}
                        onProductUpdateInCart={onProductUpdateInCart}
                        updateCost={updateCost}
                        updateDiscount={updateDiscount}
                        updateTax={updateTax}
                        updateSubTotal={updateSubTotal}
                        updatePurchaseUnit={updatePurchaseUnit}
                        productUnits={[]}
                        frontSetting={{ value: { currency_symbol: "$" } }}
                        setIsOpen={jest.fn()}
                    />
                </IntlProvider>
            </Provider>
        );

        // Edit net unit cost to 75
        const costInput = screen.getByDisplayValue("50.00");
        fireEvent.change(costInput, { target: { value: "75" } });

        // Save
        const saveButton = screen.getByRole("button", { name: /save/i });
        fireEvent.click(saveButton);

        expect(onProductUpdateInCart).toHaveBeenCalledTimes(1);
        const updated = onProductUpdateInCart.mock.calls[0][0];

        expect(updated.product_cost).toBe(75);
        expect(updated.product_price).toBe(75);
        expect(updated.fix_net_unit).toBe(75);
        expect(updated.net_unit_price).toBe(75);
    });
});
