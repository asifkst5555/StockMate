import React from "react";
import { render, screen, fireEvent, waitFor, act } from "@testing-library/react";
import "@testing-library/jest-dom";
import { Provider } from "react-redux";
import { applyMiddleware, createStore } from "redux";
import thunk from "redux-thunk";
import { IntlProvider } from "react-intl";
import { MemoryRouter } from "react-router-dom";
import _ from "lodash";

global._ = _;

import apiConfig from "../config/apiConfig";
import apiConfigWthFormData from "../config/apiConfigWthFormData";
import ProductForm from "../components/product/ProductForm";
import PurchaseForm from "../components/purchase/PurchaseForm";
import SupplierForm from "../components/supplier/SupplierForm";
import UnitsForm from "../components/units/UnitsForm";
import ProductSearch from "../shared/components/product-cart/search/ProductSearch";
import { toastType } from "../constants";

jest.mock("../config/apiConfig", () => ({
    get: jest.fn(() => Promise.resolve({ data: { data: [] } })),
    post: jest.fn(() => Promise.resolve({ data: { data: {} } })),
    put: jest.fn(() => Promise.resolve({ data: { data: {} } })),
    patch: jest.fn(() => Promise.resolve({ data: { data: {} } })),
    delete: jest.fn(() => Promise.resolve({ data: {} })),
}));

jest.mock("../config/apiConfigWthFormData", () => ({
    get: jest.fn(() => Promise.resolve({ data: { data: [] } })),
    post: jest.fn(() => Promise.resolve({ data: { data: {} } })),
    put: jest.fn(() => Promise.resolve({ data: { data: {} } })),
    patch: jest.fn(() => Promise.resolve({ data: { data: {} } })),
    delete: jest.fn(() => Promise.resolve({ data: {} })),
}));

// Mock ReactSearchAutocomplete to test onSearch/onSelect directly
jest.mock("react-search-autocomplete", () => ({
    ReactSearchAutocomplete: function MockReactSearchAutocomplete(props) {
        return (
            <input
                data-testid="mock-search-autocomplete"
                placeholder={props.placeholder}
                value={props.inputSearchString || ""}
                onChange={(e) => {
                    if (props.onSearch) props.onSearch(e.target.value);
                }}
                onKeyDown={(e) => {
                    if (e.key === "Enter" && props.onSelect) {
                        const item = props.items?.find(it => it.code === e.target.value || it.name === e.target.value);
                        props.onSelect(item || { code: e.target.value, name: e.target.value });
                    }
                }}
            />
        );
    },
}));

// Mock ReactSelect with testable select element
jest.mock("../shared/select/reactSelect", () => {
    return function MockReactSelect(props) {
        const options = props.multiLanguageOption || props.data || [];
        const currentValue = Array.isArray(props.value)
            ? props.value[0]?.value ?? ""
            : props.value?.value ?? props.defaultValue?.value ?? "";
        return (
            <div data-testid={`select-${props.name || props.title || "select"}`}>
                <label>{props.title}</label>
                <select
                    aria-label={props.title || props.name || "react-select"}
                    data-testid={`react-select-input-${props.title || props.name || "input"}`}
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
                    <option value="">Select...</option>
                    {options.map((opt) => (
                        <option
                            key={opt.id ?? opt.value}
                            value={opt.id ?? opt.value}
                        >
                            {opt.name ?? opt.attributes?.name ?? opt.label}
                        </option>
                    ))}
                </select>
                {props.errors && <span className="text-danger">{props.errors}</span>}
            </div>
        );
    };
});

// Mock ReactDatePicker to support onChangeDate triggering and clearing
jest.mock("../shared/datepicker/ReactDatePicker", () => {
    return function MockReactDatePicker(props) {
        return (
            <input
                data-testid="mock-datepicker"
                value={props.newStartDate ? String(props.newStartDate) : ""}
                onChange={(e) => {
                    const val = e.target.value ? new Date(e.target.value) : null;
                    if (props.onChangeDate) {
                        props.onChangeDate(val);
                    }
                }}
            />
        );
    };
});

// Mock sharedMethod
jest.mock("../shared/sharedMethod", () => {
    const actual = jest.requireActual("../shared/sharedMethod");
    return {
        ...actual,
        getFormattedMessage: (id) => {
            if (id === "globally.date.validate.label") return "Date field is required.";
            if (id === "globally.product-already-added.validate.message") return "This Product Already Added";
            if (id === "purchase.product-list.validate.message") return "Please select at least one product.";
            if (id === "product.input.warehouse.validate.label") return "Please select warehouse.";
            if (id === "purchase.select.supplier.validate.label") return "Please select supplier.";
            if (id === "globally.status.validate.label") return "Please select status.";
            if (id === "globally.input.name.validate.label") return "Name field is required.";
            if (id === "globally.input.email.validate.label") return "Email field is required.";
            if (id === "globally.input.email.valid.validate.label") return "Please enter valid email.";
            if (id === "unit.modal.input.short-name.validate.label") return "Short name is required.";
            if (id === "unit.modal.input.base-unit.validate.label") return "Base unit is required.";
            return id || "";
        },
        placeholderText: (id) => id || "",
        getFormattedOptions: (opts) => opts || [],
        currencySymbolHandling: (_cfg, symbol, price) => `${symbol || "$"}${price}`,
    };
});

const silentIntlError = () => {};

const makeStore = (initialState = {}) => {
    const actions = [];
    const reducer = (
        state = {
            productSales: [],
            productUnits: [
                { id: 10, attributes: { name: "Kilogram" } },
                { id: 20, attributes: { name: "Gram" } },
            ],
            purchaseProducts: [],
            products: [],
            baseUnits: [
                { id: 1, name: "Kilogram", attributes: { name: "Kilogram" } },
                { id: 2, name: "Meter", attributes: { name: "Meter" } },
            ],
            base: [
                { id: 1, name: "Kilogram", attributes: { name: "Kilogram" } },
                { id: 2, name: "Meter", attributes: { name: "Meter" } },
            ],
            units: [],
            brands: [],
            productCategories: [],
            warehouses: [{ id: 1, name: "Warehouse 1" }],
            suppliers: [{ id: 1, name: "Supplier 1" }],
            frontSetting: { value: { currency_symbol: "$" } },
            allConfigData: { is_currency_right: "false" },
            isSaving: false,
            ...initialState,
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

describe("Phase C2 Batch 5B — Frontend Inventory, Units, Purchasing UX & Regression", () => {

    beforeEach(() => {
        apiConfig.get.mockImplementation(() => Promise.resolve({ data: { data: [] } }));
        apiConfig.post.mockImplementation(() => Promise.resolve({ data: { data: {} } }));
        apiConfig.put.mockImplementation(() => Promise.resolve({ data: { data: {} } }));
        apiConfig.patch.mockImplementation(() => Promise.resolve({ data: { data: {} } }));
        apiConfig.delete.mockImplementation(() => Promise.resolve({ data: {} }));
        apiConfigWthFormData.get.mockImplementation(() => Promise.resolve({ data: { data: [] } }));
        apiConfigWthFormData.post.mockImplementation(() => Promise.resolve({ data: { data: {} } }));
        apiConfigWthFormData.put.mockImplementation(() => Promise.resolve({ data: { data: {} } }));
        apiConfigWthFormData.patch.mockImplementation(() => Promise.resolve({ data: { data: {} } }));
        apiConfigWthFormData.delete.mockImplementation(() => Promise.resolve({ data: {} }));
    });

    describe("B5-03: PurchaseForm Date Validation & Crash Protection", () => {
        test("empty date displays validation error and blocks submission without crashing", () => {
            const store = makeStore();
            const mockAddPurchase = jest.fn();

            render(
                <Provider store={store}>
                    <IntlProvider locale="en" messages={{}} onError={silentIntlError}>
                        <MemoryRouter>
                            <PurchaseForm addPurchaseData={mockAddPurchase} />
                        </MemoryRouter>
                    </IntlProvider>
                </Provider>
            );

            const datePicker = screen.getByTestId("mock-datepicker");
            // Clear date
            fireEvent.change(datePicker, { target: { value: "" } });

            // Submit form
            const submitBtn = screen.getByRole("button", { name: /save/i });
            fireEvent.click(submitBtn);

            // Error message displayed under datepicker without ReferenceError crash
            expect(screen.getByText("Date field is required.")).toBeInTheDocument();
            expect(mockAddPurchase).not.toHaveBeenCalled();
        });

        test("selecting a valid date clears error and repeated changes do not crash", () => {
            const store = makeStore();
            const mockAddPurchase = jest.fn();

            render(
                <Provider store={store}>
                    <IntlProvider locale="en" messages={{}} onError={silentIntlError}>
                        <MemoryRouter>
                            <PurchaseForm addPurchaseData={mockAddPurchase} />
                        </MemoryRouter>
                    </IntlProvider>
                </Provider>
            );

            const datePicker = screen.getByTestId("mock-datepicker");

            // 1. Clear date -> submit -> error displayed
            fireEvent.change(datePicker, { target: { value: "" } });
            fireEvent.click(screen.getByRole("button", { name: /save/i }));
            expect(screen.getByText("Date field is required.")).toBeInTheDocument();

            // 2. Select valid date -> error clears immediately
            fireEvent.change(datePicker, { target: { value: "2026-11-25" } });
            expect(screen.queryByText("Date field is required.")).not.toBeInTheDocument();

            // 3. Clear date again -> submit -> error reappears without ReferenceError crash
            fireEvent.change(datePicker, { target: { value: "" } });
            fireEvent.click(screen.getByRole("button", { name: /save/i }));
            expect(screen.getByText("Date field is required.")).toBeInTheDocument();

            // 4. Repeated rapid changes
            fireEvent.change(datePicker, { target: { value: "2026-11-26" } });
            fireEvent.change(datePicker, { target: { value: "" } });
            fireEvent.change(datePicker, { target: { value: "2026-11-27" } });
            expect(screen.queryByText("Date field is required.")).not.toBeInTheDocument();
        });
    });

    describe("C2-33: Duplicate Purchase Product Validation UX", () => {
        test("ProductSearch adds new product but blocks existing product with toast message", () => {
            const store = makeStore();
            const mockSetUpdateProducts = jest.fn();
            const mockSearchPurchase = jest.fn();
            const mockHandleValidation = jest.fn();

            const products = [
                { id: 101, attributes: { name: "Item 1", code: "CODE-1", stock: { quantity: 10 } } },
                { id: 102, attributes: { name: "Item 2", code: "CODE-2", stock: { quantity: 5 } } },
            ];
            const customProducts = [
                { product_id: 101, id: 101, name: "Item 1", code: "CODE-1" },
                { product_id: 102, id: 102, name: "Item 2", code: "CODE-2" },
            ];
            const currentCart = [
                { product_id: 101, id: 101, name: "Item 1", code: "CODE-1", quantity: 2 },
            ];

            render(
                <Provider store={store}>
                    <IntlProvider locale="en" messages={{}} onError={silentIntlError}>
                        <ProductSearch
                            values={{ warehouse_id: { value: 1, label: "WH1" } }}
                            products={products}
                            updateProducts={currentCart}
                            setUpdateProducts={mockSetUpdateProducts}
                            customProducts={customProducts}
                            searchPurchaseProduct={mockSearchPurchase}
                            handleValidation={mockHandleValidation}
                            isAllProducts={true}
                        />
                    </IntlProvider>
                </Provider>
            );

            // Attempt to search & add existing product (CODE-1) -> Blocked
            const searchInput = screen.getByTestId("mock-search-autocomplete");
            fireEvent.change(searchInput, { target: { value: "CODE-1" } });

            // Toast error action recorded in Redux store
            const toastAction = store.recordedActions.find(
                (a) => a.type === "ADD_TOAST" && a.payload?.text?.includes("Already Added")
            );
            expect(toastAction).toBeDefined();
            expect(toastAction.payload.type).toBe(toastType.ERROR);
            expect(mockSetUpdateProducts).not.toHaveBeenCalled();

            // Now search & add new product (CODE-2) -> Allowed
            fireEvent.change(searchInput, { target: { value: "CODE-2" } });
            expect(mockSetUpdateProducts).toHaveBeenCalledWith([
                ...currentCart,
                customProducts[1],
            ]);
        });

        test("PurchaseForm blocks submission if duplicate products exist in cart", async () => {
            const store = makeStore();
            const mockAddPurchase = jest.fn();

            render(
                <Provider store={store}>
                    <IntlProvider locale="en" messages={{}} onError={silentIntlError}>
                        <MemoryRouter>
                            <PurchaseForm
                                addPurchaseData={mockAddPurchase}
                                warehouses={[{ id: 1, name: "WH1", attributes: { name: "WH1" } }]}
                                suppliers={[{ id: 1, name: "Sup1", attributes: { name: "Sup1" } }]}
                                singlePurchase={{
                                    date: "2026-11-25",
                                    warehouse_id: { value: 1, label: "WH1" },
                                    supplier_id: { value: 1, label: "Sup1" },
                                    status_id: { value: 1, label: "Received" },
                                    payment_status: { value: 2, label: "Unpaid" },
                                    tax_rate: 0,
                                    tax_amount: 0,
                                    discount: 0,
                                    shipping: 0,
                                    grand_total: 100,
                                    purchase_items: [
                                        {
                                            id: 1,
                                            product_id: 101,
                                            name: "P1",
                                            code: "CODE-1",
                                            quantity: 1,
                                            product_cost: 50,
                                            net_unit_cost: 50,
                                            tax_type: "1",
                                            tax_value: 0,
                                            tax_amount: 0,
                                            discount_type: "1",
                                            discount_value: 0,
                                            discount_amount: 0,
                                            sub_total: 50,
                                            short_name: "kg",
                                            newItem: "",
                                            stocks: [{ warehouse_id: 1, quantity: 10 }],
                                        },
                                        {
                                            id: 2,
                                            product_id: 101,
                                            name: "P1 Duplicate",
                                            code: "CODE-1",
                                            quantity: 1,
                                            product_cost: 50,
                                            net_unit_cost: 50,
                                            tax_type: "1",
                                            tax_value: 0,
                                            tax_amount: 0,
                                            discount_type: "1",
                                            discount_value: 0,
                                            discount_amount: 0,
                                            sub_total: 50,
                                            short_name: "kg",
                                            newItem: "",
                                            stocks: [{ warehouse_id: 1, quantity: 10 }],
                                        },
                                    ],
                                }}
                            />
                        </MemoryRouter>
                    </IntlProvider>
                </Provider>
            );

            // Wait for singlePurchase values to populate
            await screen.findByText("P1");

            const submitBtn = screen.getByRole("button", { name: /save/i });
            fireEvent.click(submitBtn);

            // Duplicate error toast triggered and submission blocked
            const toastAction = store.recordedActions.find(
                (a) => a.type === "ADD_TOAST" && a.payload?.text?.includes("Already Added")
            );
            expect(toastAction).toBeDefined();
            expect(mockAddPurchase).not.toHaveBeenCalled();
        });
    });

    describe("B5-02: ProductForm Unit Selection & 1:1 Parity UX", () => {
        test("selecting Purchase Unit automatically syncs Sale Unit when empty", () => {
            const store = makeStore();
            const mockAddProduct = jest.fn();

            render(
                <Provider store={store}>
                    <IntlProvider locale="en" messages={{}} onError={silentIntlError}>
                        <MemoryRouter>
                            <ProductForm addProductData={mockAddProduct} />
                        </MemoryRouter>
                    </IntlProvider>
                </Provider>
            );

            // Purchase Unit select
            const purchaseUnitSelect = screen.getByTestId("react-select-input-product.input.purchase-unit.label");
            fireEvent.change(purchaseUnitSelect, { target: { value: "10" } }); // Kilogram

            // Sale Unit automatically updated to Kilogram (10)
            const saleUnitSelect = screen.getByTestId("react-select-input-product.input.sale-unit.label");
            expect(saleUnitSelect.value).toBe("10");
            expect(screen.queryByText(/Purchase Unit and Sale Unit must match/i)).not.toBeInTheDocument();
        });

        test("mismatched units display clear validation error and block form submission", () => {
            const store = makeStore();
            const mockAddProduct = jest.fn();

            render(
                <Provider store={store}>
                    <IntlProvider locale="en" messages={{}} onError={silentIntlError}>
                        <MemoryRouter>
                            <ProductForm addProductData={mockAddProduct} />
                        </MemoryRouter>
                    </IntlProvider>
                </Provider>
            );

            const purchaseUnitSelect = screen.getByTestId("react-select-input-product.input.purchase-unit.label");
            const saleUnitSelect = screen.getByTestId("react-select-input-product.input.sale-unit.label");

            // Select Kilogram (10) for Purchase Unit
            fireEvent.change(purchaseUnitSelect, { target: { value: "10" } });
            // Select Gram (20) for Sale Unit -> Mismatch!
            fireEvent.change(saleUnitSelect, { target: { value: "20" } });

            // Validation error displayed
            expect(
                screen.getByText("Purchase Unit and Sale Unit must match. Unit conversion is not currently supported.")
            ).toBeInTheDocument();

            // Submit is blocked
            const submitBtn = screen.getByRole("button", { name: /save/i });
            fireEvent.click(submitBtn);
            expect(mockAddProduct).not.toHaveBeenCalled();

            // Changing Purchase Unit to Gram (20) restores 1:1 parity and clears error
            fireEvent.change(purchaseUnitSelect, { target: { value: "20" } });
            expect(screen.queryByText(/Purchase Unit and Sale Unit must match/i)).not.toBeInTheDocument();
        });

        test("edit mode loads existing units and preserves unrelated product fields", () => {
            const store = makeStore();
            const singleProduct = [
                {
                    id: 50,
                    name: "Existing Product",
                    code: "EX-100",
                    product_category_id: { value: 1, label: "Category" },
                    brand_id: { value: 1, label: "Brand" },
                    barcode_symbol: 1,
                    product_unit: 10,
                    sale_unit: { value: 10, label: "Kilogram" },
                    purchase_unit: { value: 10, label: "Kilogram" },
                    notes: "Important product note",
                    images: { imageUrls: [], id: [] },
                    is_Edit: true,
                },
            ];

            render(
                <Provider store={store}>
                    <IntlProvider locale="en" messages={{}} onError={silentIntlError}>
                        <MemoryRouter>
                            <ProductForm singleProduct={singleProduct} />
                        </MemoryRouter>
                    </IntlProvider>
                </Provider>
            );

            // Unit values loaded
            const saleUnitSelect = screen.getByTestId("react-select-input-product.input.sale-unit.label");
            const purchaseUnitSelect = screen.getByTestId("react-select-input-product.input.purchase-unit.label");
            expect(saleUnitSelect.value).toBe("10");
            expect(purchaseUnitSelect.value).toBe("10");

            // Unrelated fields preserved
            expect(screen.getByDisplayValue("Existing Product")).toBeInTheDocument();
            expect(screen.getByDisplayValue("EX-100")).toBeInTheDocument();
            expect(screen.getByDisplayValue("Important product note")).toBeInTheDocument();
            expect(screen.queryByText(/Purchase Unit and Sale Unit must match/i)).not.toBeInTheDocument();
        });
    });

    describe("B5-10: SupplierForm Regression Coverage", () => {
        test("initializes with empty fields in create mode and disables save when name is empty", () => {
            const store = makeStore();
            const mockAddSupplier = jest.fn();

            render(
                <Provider store={store}>
                    <IntlProvider locale="en" messages={{}} onError={silentIntlError}>
                        <MemoryRouter>
                            <SupplierForm addSupplierData={mockAddSupplier} />
                        </MemoryRouter>
                    </IntlProvider>
                </Provider>
            );

            // Empty form -> Save button is disabled
            const submitBtn = screen.getByRole("button", { name: /save/i });
            expect(submitBtn).toBeDisabled();
            expect(mockAddSupplier).not.toHaveBeenCalled();
        });

        test("validates invalid email format", () => {
            const store = makeStore();
            const mockAddSupplier = jest.fn();

            render(
                <Provider store={store}>
                    <IntlProvider locale="en" messages={{}} onError={silentIntlError}>
                        <MemoryRouter>
                            <SupplierForm addSupplierData={mockAddSupplier} />
                        </MemoryRouter>
                    </IntlProvider>
                </Provider>
            );

            const nameInput = screen.getByPlaceholderText("globally.input.name.placeholder.label");
            const emailInput = screen.getByPlaceholderText("globally.input.email.placeholder.label");

            fireEvent.change(nameInput, { target: { name: "name", value: "Supplier Alpha" } });
            fireEvent.change(emailInput, { target: { name: "email", value: "invalid-email-format" } });

            const submitBtn = screen.getByRole("button", { name: /save/i });
            expect(submitBtn).not.toBeDisabled();
            fireEvent.click(submitBtn);

            expect(screen.getByText("Please enter valid email.")).toBeInTheDocument();
            expect(mockAddSupplier).not.toHaveBeenCalled();
        });

        test("submits successfully with valid data in create mode", () => {
            const store = makeStore();
            const mockAddSupplier = jest.fn();

            render(
                <Provider store={store}>
                    <IntlProvider locale="en" messages={{}} onError={silentIntlError}>
                        <MemoryRouter>
                            <SupplierForm addSupplierData={mockAddSupplier} />
                        </MemoryRouter>
                    </IntlProvider>
                </Provider>
            );

            fireEvent.change(screen.getByPlaceholderText("globally.input.name.placeholder.label"), {
                target: { name: "name", value: "Valid Supplier" },
            });
            fireEvent.change(screen.getByPlaceholderText("globally.input.email.placeholder.label"), {
                target: { name: "email", value: "supplier@example.com" },
            });
            fireEvent.change(screen.getByPlaceholderText("globally.input.phone-number.label"), {
                target: { name: "phone", value: "1234567890" },
            });
            fireEvent.change(screen.getByPlaceholderText("globally.input.country.placeholder.label"), {
                target: { name: "country", value: "USA" },
            });
            fireEvent.change(screen.getByPlaceholderText("globally.input.city.placeholder.label"), {
                target: { name: "city", value: "New York" },
            });
            fireEvent.change(screen.getByPlaceholderText("globally.input.address.placeholder.label"), {
                target: { name: "address", value: "100 Broadway" },
            });

            const submitBtn = screen.getByRole("button", { name: /save/i });
            expect(submitBtn).not.toBeDisabled();
            fireEvent.click(submitBtn);

            expect(mockAddSupplier).toHaveBeenCalledWith({
                name: "Valid Supplier",
                email: "supplier@example.com",
                phone: "1234567890",
                country: "USA",
                city: "New York",
                address: "100 Broadway",
            });
        });

        test("loads existing values in edit mode and disables save when unchanged", async () => {
            const store = makeStore();
            const singleSupplier = [
                {
                    id: 99,
                    name: "Existing Supplier",
                    email: "existing@example.com",
                    phone: "9876543210",
                    country: "UK",
                    city: "London",
                    address: "10 Downing St",
                },
            ];

            render(
                <Provider store={store}>
                    <IntlProvider locale="en" messages={{}} onError={silentIntlError}>
                        <MemoryRouter>
                            <SupplierForm id={99} singleSupplier={singleSupplier} />
                        </MemoryRouter>
                    </IntlProvider>
                </Provider>
            );

            expect(screen.getByDisplayValue("Existing Supplier")).toBeInTheDocument();
            expect(screen.getByDisplayValue("existing@example.com")).toBeInTheDocument();

            // Unchanged edit form button is disabled
            const submitBtn = screen.getByRole("button", { name: /save/i });
            expect(submitBtn).toBeDisabled();

            // Change name -> Enabled -> Submits to editSupplier API
            fireEvent.change(screen.getByDisplayValue("Existing Supplier"), {
                target: { name: "name", value: "Updated Supplier" },
            });
            expect(submitBtn).not.toBeDisabled();
            fireEvent.click(submitBtn);

            await waitFor(() => {
                expect(apiConfig.patch).toHaveBeenCalledWith(
                    expect.stringContaining("suppliers/99"),
                    expect.objectContaining({ name: "Updated Supplier" })
                );
            });
        });
    });

    describe("B5-10: UnitsForm Regression Coverage", () => {
        test("initializes and disables save when name is empty, validates required fields", () => {
            const store = makeStore();
            const mockAddProductData = jest.fn();
            const mockHandleClose = jest.fn();

            render(
                <Provider store={store}>
                    <IntlProvider locale="en" messages={{}} onError={silentIntlError}>
                        <UnitsForm
                            show={true}
                            addProductData={mockAddProductData}
                            handleClose={mockHandleClose}
                        />
                    </IntlProvider>
                </Provider>
            );

            const submitBtn = screen.getByRole("button", { name: /save/i });
            expect(submitBtn).toBeDisabled();

            // Provide name -> button enabled -> click without short_name triggers validation error
            fireEvent.change(screen.getByPlaceholderText("globally.input.name.placeholder.label"), {
                target: { name: "name", value: "Metric Ton" },
            });
            expect(submitBtn).not.toBeDisabled();
            fireEvent.click(submitBtn);

            expect(mockAddProductData).not.toHaveBeenCalled();
            expect(screen.getByText("Short name is required.")).toBeInTheDocument();
        });

        test("submits valid unit data in create mode", () => {
            const store = makeStore();
            const mockAddProductData = jest.fn();
            const mockHandleClose = jest.fn();

            render(
                <Provider store={store}>
                    <IntlProvider locale="en" messages={{}} onError={silentIntlError}>
                        <UnitsForm
                            show={true}
                            addProductData={mockAddProductData}
                            handleClose={mockHandleClose}
                        />
                    </IntlProvider>
                </Provider>
            );

            fireEvent.change(screen.getByPlaceholderText("globally.input.name.placeholder.label"), {
                target: { name: "name", value: "Metric Ton" },
            });
            fireEvent.change(screen.getByPlaceholderText("unit.modal.input.short-name.placeholder.label"), {
                target: { name: "short_name", value: "mt" },
            });

            const baseUnitSelect = screen.getByTestId("react-select-input-base-unit.title");
            fireEvent.change(baseUnitSelect, { target: { value: "1" } });

            const submitBtn = screen.getByRole("button", { name: /save/i });
            fireEvent.click(submitBtn);

            expect(mockAddProductData).toHaveBeenCalled();
            const passedParams = mockAddProductData.mock.calls[0][0];
            expect(passedParams.get("name")).toBe("Metric Ton");
            expect(passedParams.get("short_name")).toBe("mt");
            expect(passedParams.get("base_unit")).toBe("1");
        });

        test("loads existing unit in edit mode and calls editUnit API on change", async () => {
            const store = makeStore();
            const mockHandleClose = jest.fn();
            const singleUnit = {
                id: 42,
                name: "Pound",
                short_name: "lb",
                base_unit: 1,
            };

            render(
                <Provider store={store}>
                    <IntlProvider locale="en" messages={{}} onError={silentIntlError}>
                        <UnitsForm
                            show={true}
                            singleUnit={singleUnit}
                            handleClose={mockHandleClose}
                        />
                    </IntlProvider>
                </Provider>
            );

            expect(screen.getByDisplayValue("Pound")).toBeInTheDocument();
            expect(screen.getByDisplayValue("lb")).toBeInTheDocument();

            // Wait for singleUnit values to populate base_unit
            await waitFor(() => {
                const selectEl = screen.getByTestId("react-select-input-base-unit.title");
                expect(selectEl.value).toBe("1");
            });

            // Modify name and submit
            fireEvent.change(screen.getByDisplayValue("Pound"), {
                target: { name: "name", value: "Pound Heavy" },
            });

            fireEvent.click(screen.getByRole("button", { name: /save/i }));

            await waitFor(() => {
                expect(apiConfig.patch).toHaveBeenCalledWith(
                    expect.stringContaining("units/42"),
                    expect.any(URLSearchParams)
                );
            });
            const patchCall = apiConfig.patch.mock.calls.find(call => call[0].includes("units/42"));
            expect(patchCall[1].get("name")).toBe("Pound Heavy");
        });
    });
});
