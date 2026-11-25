import React, { useState } from "react";
import { render, screen, fireEvent } from "@testing-library/react";
import "@testing-library/jest-dom";
import { Provider } from "react-redux";
import { applyMiddleware, createStore } from "redux";
import thunk from "redux-thunk";
import { IntlProvider } from "react-intl";
import { MemoryRouter } from "react-router-dom";

import apiConfig from "../config/apiConfig";
import { editPurchaseReturnArray } from "../components/purchaseReturn/editPurchaseReturnArray";
import ProductTableBody from "../shared/components/sales/ProductTableBody";
import PurchaseTable from "../shared/components/purchase/PurchaseTable";
import PurchaseReturnForm from "../components/purchaseReturn/PurchaseReturnForm";
import SaleReturnForm from "../components/saleReturn/SaleReturnForm";

jest.mock("../config/apiConfig", () => ({
    get: jest.fn(() => Promise.resolve({ data: { data: [] } })),
    post: jest.fn(() => Promise.resolve({ data: { data: {} } })),
    put: jest.fn(() => Promise.resolve({ data: { data: {} } })),
    patch: jest.fn(() => Promise.resolve({ data: { data: {} } })),
    delete: jest.fn(() => Promise.resolve({ data: {} })),
}));

jest.mock("../shared/select/reactSelect", () => {
    return function MockReactSelect(props) {
        const options = props.multiLanguageOption || props.data || [];
        const currentValue = props.value?.value ?? props.defaultValue?.value ?? "";
        return (
            <div data-testid={`select-${props.name || props.title}`}>
                <select
                    aria-label={props.name || props.title}
                    value={currentValue}
                    onChange={(e) => {
                        const matched = options.find(
                            (opt) => String(opt.id ?? opt.value) === e.target.value
                        );
                        if (props.onChange && matched) {
                            props.onChange({
                                value: matched.id ?? matched.value,
                                label: matched.name ?? matched.attributes?.name ?? matched.label,
                            });
                        }
                    }}
                >
                    {options.map((opt) => (
                        <option
                            key={opt.id ?? opt.value}
                            value={opt.id ?? opt.value}
                        >
                            {opt.name ?? opt.attributes?.name ?? opt.label}
                        </option>
                    ))}
                </select>
            </div>
        );
    };
});

jest.mock("../shared/datepicker/ReactDatePicker", () => () => (
    <input data-testid="mock-datepicker" readOnly value="2026-11-25" />
));

jest.mock("../shared/components/product-cart/search/ProductSearch", () => () => (
    <div data-testid="mock-product-search" />
));

jest.mock("../shared/sharedMethod", () => {
    const actual = jest.requireActual("../shared/sharedMethod");
    return {
        ...actual,
        getFormattedMessage: (id) => id || "",
        placeholderText: (id) => id || "",
        getFormattedOptions: (opts) => opts || [],
        currencySymbolHandling: (_cfg, symbol, price) => `${symbol || "$"}${price}`,
    };
});

const silentIntlError = () => {};

beforeEach(() => {
    apiConfig.get.mockResolvedValue({ data: { data: [] } });
    apiConfig.post.mockResolvedValue({ data: { data: {} } });
    apiConfig.put.mockResolvedValue({ data: { data: {} } });
    apiConfig.patch.mockResolvedValue({ data: { data: {} } });
    apiConfig.delete.mockResolvedValue({ data: {} });
});

const makeStore = () => {
    const actions = [];
    const reducer = (
        state = {
            productSales: [],
            productUnits: [],
            purchaseProducts: [],
            products: [],
            frontSetting: { value: { currency_symbol: "$" } },
            allConfigData: { is_currency_right: "false" },
            isSaving: false,
        },
        action
    ) => {
        actions.push(action);
        return state;
    };
    const store = createStore(reducer, applyMiddleware(thunk));
    store.recordedActions = actions;
    return store;
};

describe("Phase C2 Batch 3B-2 — editPurchaseReturnArray Compatibility", () => {
    test("supports both discount_value (PurchaseReturnItem) and discount_vale (PurchaseItem) and filters warehouse stock", () => {
        const itemsFromPurchase = [
            {
                id: 11,
                product_id: 101,
                product_cost: 50,
                tax_type: 1,
                tax_value: 0,
                tax_amount: 0,
                discount_type: 2,
                discount_vale: 5.5, // PurchaseItem PHP attribute typo
                discount_amount: 5.5,
                quantity: 4,
                sub_total: 178,
                purchase_unit: { id: 1, short_name: "pc" },
                product: {
                    name: "Item One",
                    code: "P-101",
                    product_unit: 1,
                    stock_alert: 2,
                    stocks: [
                        { warehouse_id: 10, quantity: 9 },
                        { warehouse_id: 20, quantity: 4 },
                    ],
                },
            },
            {
                id: 12,
                product_id: 102,
                product_cost: 30,
                tax_type: 1,
                tax_value: 0,
                tax_amount: 0,
                discount_type: 2,
                discount_value: 2.25, // PurchaseReturnItem canonical attribute
                discount_amount: 2.25,
                quantity: 3,
                sub_total: 83.25,
                purchase_unit: { id: 1, short_name: "pc" },
                product: {
                    name: "Item Two",
                    code: "P-102",
                    product_unit: 1,
                    stock_alert: 1,
                    stocks: [{ warehouse_id: 10, quantity: 7 }],
                },
            },
        ];

        const createMapped = editPurchaseReturnArray(itemsFromPurchase, 10, true);
        expect(createMapped[0].discount_value).toBe(5.5);
        expect(createMapped[0].quantity).toBe(0);
        expect(createMapped[0].id).toBe(11);
        expect(createMapped[0].purchase_return_item_id).toBe("");
        expect(createMapped[0].product_id).toBe(101);
        expect(createMapped[0].product_cost).toBe(50);
        expect(createMapped[0].purchase_unit).toBe(1);
        expect(createMapped[0].stocks).toEqual([{ warehouse_id: 10, quantity: 9 }]);
        expect(createMapped[1].discount_value).toBe(2.25);
        expect(createMapped[1].purchase_return_item_id).toBe("");

        const editMappedExplicit = editPurchaseReturnArray(itemsFromPurchase, 20, false);
        expect(editMappedExplicit[0].quantity).toBe(4);
        expect(editMappedExplicit[0].purchase_return_item_id).toBe(11);
        expect(editMappedExplicit[0].stocks).toEqual([{ warehouse_id: 20, quantity: 4 }]);

        const editMappedOmitted = editPurchaseReturnArray(itemsFromPurchase, 10);
        expect(editMappedOmitted[0].purchase_return_item_id).toBe(11);
        expect(editMappedOmitted[1].purchase_return_item_id).toBe(12);
    });
});

describe("Phase C2 Batch 3B-2 — Immutable ProductTableBody & PurchaseTable", () => {
    test("ProductTableBody updates quantity & sub_total immutably in SaleReturn mode (0..sold_quantity) and preserves Sales mode min=1", () => {
        const frozenReturnRow = Object.freeze({
            id: 1,
            name: "Rice",
            code: "R-1",
            short_name: "kg",
            fix_net_unit: 10,
            discount_type: 2,
            discount_value: 0,
            discount_amount: 0,
            tax_type: 1,
            tax_value: 0,
            tax_amount: 0,
            quantity: 0,
            sold_quantity: 2,
            sub_total: 0,
            stock: 15,
            isSaleReturn: true,
        });

        const store = makeStore();
        const SaleTableHarness = ({ initialRows }) => {
            const [rows, setRows] = useState(initialRows);
            return (
                <>
                    <table>
                        <tbody>
                            <ProductTableBody
                                singleProduct={rows[0]}
                                index={0}
                                updateProducts={rows}
                                setUpdateProducts={setRows}
                                frontSetting={{ value: { currency_symbol: "$" } }}
                            />
                        </tbody>
                    </table>
                    <span data-testid="state-qty">{rows[0].quantity}</span>
                    <span data-testid="state-subtotal">{rows[0].sub_total}</span>
                </>
            );
        };

        const { unmount } = render(
            <IntlProvider locale="en" messages={{}} onError={silentIntlError}>
                <Provider store={store}>
                    <SaleTableHarness initialRows={[frozenReturnRow]} />
                </Provider>
            </IntlProvider>
        );

        fireEvent.click(screen.getByText("+"));
        expect(screen.getByTestId("state-qty")).toHaveTextContent("1");
        expect(screen.getByTestId("state-subtotal")).toHaveTextContent("10");
        expect(frozenReturnRow.quantity).toBe(0);
        expect(frozenReturnRow.sub_total).toBe(0);

        fireEvent.click(screen.getByText("+"));
        expect(screen.getByTestId("state-qty")).toHaveTextContent("2");
        expect(screen.getByTestId("state-subtotal")).toHaveTextContent("20");

        // Exceeding sold_quantity=2 stays capped and emits toast
        fireEvent.click(screen.getByText("+"));
        expect(screen.getByTestId("state-qty")).toHaveTextContent("2");

        // SaleReturn allows decrementing back down to 0
        fireEvent.click(screen.getByText("-"));
        fireEvent.click(screen.getByText("-"));
        expect(screen.getByTestId("state-qty")).toHaveTextContent("0");
        expect(screen.getByTestId("state-subtotal")).toHaveTextContent("0");
        unmount();

        // Preserve Sales / Quotation floor at quantity=1
        const frozenSaleRow = Object.freeze({
            ...frozenReturnRow,
            isSaleReturn: false,
            quantity: 1,
            sub_total: 10,
        });
        render(
            <IntlProvider locale="en" messages={{}} onError={silentIntlError}>
                <Provider store={store}>
                    <SaleTableHarness initialRows={[frozenSaleRow]} />
                </Provider>
            </IntlProvider>
        );
        fireEvent.click(screen.getByText("-"));
        expect(screen.getByTestId("state-qty")).toHaveTextContent("1");
    });

    test("PurchaseTable resolves warehouseName stock without mutating frozen product and preserves Purchase vs PurchaseReturn min qty", () => {
        const frozenPurchaseRow = Object.freeze({
            id: 5,
            name: "Sugar",
            code: "S-5",
            short_name: "kg",
            fix_net_unit: 20,
            net_unit_cost: 20,
            discount_type: 2,
            discount_value: 0,
            discount_amount: 0,
            tax_type: 1,
            tax_value: 0,
            tax_amount: 0,
            quantity: 1,
            sub_total: 20,
            stock: 0,
            in_warehouse_stock: [
                { name: "Main WH", total_quantity: 14 },
                { name: "Branch WH", total_quantity: 6 },
            ],
        });

        const store = makeStore();
        const PurchaseTableHarness = ({ initialRows, isPurchaseReturn }) => {
            const [rows, setRows] = useState(initialRows);
            return (
                <>
                    <table>
                        <tbody>
                            <PurchaseTable
                                singleProduct={rows[0]}
                                index={0}
                                updateProducts={rows}
                                setUpdateProducts={setRows}
                                frontSetting={{ value: { currency_symbol: "$" } }}
                                warehouseName="Branch WH"
                                isPurchaseReturn={isPurchaseReturn}
                            />
                        </tbody>
                    </table>
                    <span data-testid="p-qty">{rows[0].quantity}</span>
                    <span data-testid="p-sub">{rows[0].sub_total}</span>
                </>
            );
        };

        const { unmount } = render(
            <IntlProvider locale="en" messages={{}} onError={silentIntlError}>
                <Provider store={store}>
                    <PurchaseTableHarness
                        initialRows={[frozenPurchaseRow]}
                        isPurchaseReturn={true}
                    />
                </Provider>
            </IntlProvider>
        );

        expect(screen.getByText(/6\s+kg/)).toBeInTheDocument();
        expect(frozenPurchaseRow.stock).toBe(0);

        fireEvent.click(screen.getByText("+"));
        expect(screen.getByTestId("p-qty")).toHaveTextContent("2");
        expect(screen.getByTestId("p-sub")).toHaveTextContent("40");
        expect(frozenPurchaseRow.quantity).toBe(1);

        // PurchaseReturn allows decrementing back to 0
        fireEvent.click(screen.getByText("-"));
        fireEvent.click(screen.getByText("-"));
        expect(screen.getByTestId("p-qty")).toHaveTextContent("0");
        expect(screen.getByTestId("p-sub")).toHaveTextContent("0");
        unmount();

        // Standard PurchaseForm preserves minimum quantity = 1
        render(
            <IntlProvider locale="en" messages={{}} onError={silentIntlError}>
                <Provider store={store}>
                    <PurchaseTableHarness
                        initialRows={[frozenPurchaseRow]}
                        isPurchaseReturn={false}
                    />
                </Provider>
            </IntlProvider>
        );
        fireEvent.click(screen.getByText("-"));
        expect(screen.getByTestId("p-qty")).toHaveTextContent("1");
    });
});

describe("Phase C2 Batch 3B-2 — PurchaseReturnForm & SaleReturnForm Submission & Filtering", () => {
    const buildReturnRow = (id, name, price, qty, extra = {}) => ({
        id,
        product_id: 100 + id,
        name,
        code: `C-${id}`,
        short_name: "pc",
        fix_net_unit: price,
        net_unit_cost: price,
        product_cost: price,
        product_price: price,
        discount_type: 2,
        discount_value: 0,
        discount_amount: 0,
        tax_type: 1,
        tax_value: 0,
        tax_amount: 0,
        quantity: qty,
        sub_total: Number((price * qty).toFixed(2)),
        in_warehouse_stock: [{ name: "Main WH", total_quantity: 22 }],
        ...extra,
    });

    test("PurchaseReturnForm exposes only Received(1)/Pending(2) statuses, passes warehouseName stock, and filters 0-qty rows on submit", () => {
        const store = makeStore();
        const addPurchaseReturnData = jest.fn();

        render(
            <IntlProvider locale="en" messages={{}} onError={silentIntlError}>
                <Provider store={store}>
                    <MemoryRouter>
                        <PurchaseReturnForm
                            warehouses={[{ id: 7, attributes: { name: "Main WH" } }]}
                            suppliers={[{ id: 3, attributes: { name: "Acme" } }]}
                            addPurchaseReturnData={addPurchaseReturnData}
                            createSinglePurchase={{
                                date: "2026-11-25",
                                warehouse_id: { value: 7, label: "Main WH" },
                                supplier_id: { value: 3, label: "Acme" },
                                discount: 0,
                                orderTax: 10,
                                shipping: 5,
                                notes: "Partial return",
                                status_id: { value: 1, label: "status.filter.received.label" },
                                purchase_id: 55,
                                purchase_return_items: [
                                    buildReturnRow(1, "Item A", 50, 2),
                                    buildReturnRow(2, "Item B", 40, 0),
                                ],
                            }}
                        />
                    </MemoryRouter>
                </Provider>
            </IntlProvider>
        );

        // Status options must NOT include Ordered (3)
        const statusSelect = screen.getByLabelText("status");
        const statusValues = Array.from(statusSelect.options).map((o) => o.value);
        expect(statusValues).toEqual(["1", "2"]);
        expect(statusValues).not.toContain("3");

        // warehouseName prop propagated to PurchaseTable so Main WH stock (22) renders
        expect(screen.getAllByText(/22\s+pc/).length).toBeGreaterThanOrEqual(1);

        // Submit -> filters out Item B (quantity=0)
        fireEvent.click(screen.getByText("globally.save-btn"));
        expect(addPurchaseReturnData).toHaveBeenCalledTimes(1);
        const payload = addPurchaseReturnData.mock.calls[0][0];
        expect(payload.status).toBe(1);
        expect(payload.purchase_return_items).toHaveLength(1);
        expect(payload.purchase_return_items[0].id).toBe(1);
        expect(payload.tax_amount).toBe("10.00");
        expect(payload.grand_total).toBe("115.00");
    });

    test("PurchaseReturnForm wired with editPurchaseReturnArray omits source purchase_item id from purchase_return_item_id in create mode and preserves it in edit mode", () => {
        const purchaseItems = [
            {
                id: 584,
                product_id: 101,
                product_cost: 60,
                tax_type: 1,
                tax_value: 0,
                tax_amount: 0,
                discount_type: 2,
                discount_vale: 5,
                discount_amount: 5,
                quantity: 6,
                sub_total: 330,
                purchase_unit: { id: 163, short_name: "1kg" },
                product: {
                    name: "Prod A",
                    code: "P-A",
                    product_unit: 163,
                    stock_alert: 2,
                    stocks: [{ warehouse_id: 7, quantity: 50 }],
                },
            },
            {
                id: 585,
                product_id: 102,
                product_cost: 30,
                tax_type: 1,
                tax_value: 0,
                tax_amount: 0,
                discount_type: 2,
                discount_value: 0,
                discount_amount: 0,
                quantity: 5,
                sub_total: 150,
                purchase_unit: { id: 163, short_name: "1kg" },
                product: {
                    name: "Prod B",
                    code: "P-B",
                    product_unit: 163,
                    stock_alert: 2,
                    stocks: [{ warehouse_id: 7, quantity: 30 }],
                },
            },
        ];

        const addPurchaseReturnData = jest.fn();
        const { unmount } = render(
            <IntlProvider locale="en" messages={{}} onError={silentIntlError}>
                <Provider store={makeStore()}>
                    <MemoryRouter>
                        <PurchaseReturnForm
                            warehouses={[{ id: 7, attributes: { name: "Main WH" } }]}
                            suppliers={[{ id: 3, attributes: { name: "Acme" } }]}
                            addPurchaseReturnData={addPurchaseReturnData}
                            createSinglePurchase={{
                                date: "2026-11-25",
                                warehouse_id: { value: 7, label: "Main WH" },
                                supplier_id: { value: 3, label: "Acme" },
                                discount: 0,
                                orderTax: 0,
                                shipping: 0,
                                notes: "",
                                status_id: { value: 2, label: "status.filter.pending.label" },
                                purchase_id: 305,
                                purchase_return_items: editPurchaseReturnArray(purchaseItems, 7, true),
                            }}
                        />
                    </MemoryRouter>
                </Provider>
            </IntlProvider>
        );

        const plusBtns = screen.getAllByText("+");
        fireEvent.click(plusBtns[0]); // Set Prod A qty = 1, leave Prod B qty = 0
        fireEvent.click(screen.getByText("globally.save-btn"));

        expect(addPurchaseReturnData).toHaveBeenCalledTimes(1);
        const createPayload = addPurchaseReturnData.mock.calls[0][0];
        expect(createPayload.purchase_return_items).toHaveLength(1);
        expect(createPayload.purchase_return_items[0].purchase_return_item_id).toBe("");
        expect(createPayload.purchase_return_items[0].discount_value).toBe(5);
        unmount();

        // Edit mode preserves existing purchase_return_item_id
        apiConfig.put.mockResolvedValueOnce({ data: { data: { id: 77 } } });
        render(
            <IntlProvider locale="en" messages={{}} onError={silentIntlError}>
                <Provider store={makeStore()}>
                    <MemoryRouter>
                        <PurchaseReturnForm
                            id={77}
                            warehouses={[{ id: 7, attributes: { name: "Main WH" } }]}
                            suppliers={[{ id: 3, attributes: { name: "Acme" } }]}
                            singlePurchase={{
                                date: "2026-11-25",
                                warehouse_id: { value: 7, label: "Main WH" },
                                supplier_id: { value: 3, label: "Acme" },
                                discount: 0,
                                orderTax: 0,
                                shipping: 0,
                                notes: "",
                                status_id: { value: 2, label: "status.filter.pending.label" },
                                purchase_return_items: editPurchaseReturnArray(
                                    [{ ...purchaseItems[0], id: 901, quantity: 2 }],
                                    7
                                ),
                            }}
                        />
                    </MemoryRouter>
                </Provider>
            </IntlProvider>
        );

        fireEvent.click(screen.getByText("globally.save-btn"));
        expect(apiConfig.put).toHaveBeenCalledTimes(1);
        const editPayload = apiConfig.put.mock.calls[0][1];
        expect(editPayload.purchase_return_items[0].purchase_return_item_id).toBe(901);
    });

    test("SaleReturnForm filters untouched 0-qty rows on submit and blocks submission when all rows are 0", () => {
        const store = makeStore();
        const addSaleData = jest.fn();

        const { unmount } = render(
            <IntlProvider locale="en" messages={{}} onError={silentIntlError}>
                <Provider store={store}>
                    <MemoryRouter>
                        <SaleReturnForm
                            addSaleData={addSaleData}
                            singleSale={{
                                date: "2026-11-25",
                                customer_id: { value: 9, label: "John" },
                                warehouse_id: { value: 7, label: "Main WH" },
                                tax_rate: 10,
                                tax_amount: 0,
                                discount: 10,
                                shipping: 5,
                                grand_total: 0,
                                status_id: 1,
                                note: null,
                                sale_id: 90,
                                sale_reference: "SL_90",
                                isCreateSaleReturn: true,
                                sale_items: [
                                    buildReturnRow(1, "Item A", 50, 2, {
                                        sold_quantity: 3,
                                        stock: 10,
                                        isSaleReturn: true,
                                    }),
                                    buildReturnRow(2, "Item B", 30, 0, {
                                        sold_quantity: 2,
                                        stock: 8,
                                        isSaleReturn: true,
                                    }),
                                ],
                            }}
                        />
                    </MemoryRouter>
                </Provider>
            </IntlProvider>
        );

        fireEvent.click(screen.getByText("globally.save-btn"));
        expect(addSaleData).toHaveBeenCalledTimes(1);
        const payload = addSaleData.mock.calls[0][0];
        expect(payload.sale_return_items).toHaveLength(1);
        expect(payload.sale_return_items[0].id).toBe(1);
        // (100 - 10) * 10% = 9.00; grand_total = 90 + 9 + 5 = 104
        expect(payload.tax_amount).toBe("9.00");
        expect(payload.grand_total).toBe(104);
        unmount();

        // All-zero return must be blocked
        const blockedSubmit = jest.fn();
        render(
            <IntlProvider locale="en" messages={{}} onError={silentIntlError}>
                <Provider store={makeStore()}>
                    <MemoryRouter>
                        <SaleReturnForm
                            addSaleData={blockedSubmit}
                            singleSale={{
                                date: "2026-11-25",
                                customer_id: { value: 9, label: "John" },
                                warehouse_id: { value: 7, label: "Main WH" },
                                tax_rate: 0,
                                tax_amount: 0,
                                discount: 0,
                                shipping: 0,
                                grand_total: 0,
                                status_id: 2,
                                note: "",
                                sale_id: 91,
                                sale_reference: "SL_91",
                                isCreateSaleReturn: true,
                                sale_items: [
                                    buildReturnRow(1, "Item A", 50, 0, {
                                        sold_quantity: 3,
                                        stock: 10,
                                        isSaleReturn: true,
                                    }),
                                ],
                            }}
                        />
                    </MemoryRouter>
                </Provider>
            </IntlProvider>
        );
        fireEvent.click(screen.getByText("globally.save-btn"));
        expect(blockedSubmit).not.toHaveBeenCalled();
    });
});
