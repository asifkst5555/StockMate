import React, { useState } from "react";
import fs from "fs";
import path from "path";
import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import "@testing-library/jest-dom";
import { Provider } from "react-redux";
import { createStore } from "redux";

import PrintTable from "../components/printBarcode/PrintTable";
import PrintButton from "../components/printBarcode/PrintButton";
import ProductSearchbar from "../frontend/components/product/ProductSearchbar";
import barcodeOptions from "../shared/option-lists/barcode.json";
import apiConfig from "../config/apiConfig";

jest.mock("../config/apiConfig", () => ({
    get: jest.fn(),
}));

jest.mock("../shared/sharedMethod", () => ({
    decimalValidate: jest.fn(),
    currencySymbolHandling: (_cfg, symbol, price) => `${symbol || "$"}${price}`,
    getFormattedMessage: (key) => key,
    placeholderText: (key) => key,
}));

jest.mock("react-search-autocomplete", () => ({
    ReactSearchAutocomplete: ({ onSearch, inputSearchString, placeholder }) => (
        <input
            data-test="search-input"
            placeholder={placeholder}
            value={inputSearchString || ""}
            onChange={(e) => onSearch(e.target.value)}
        />
    ),
}));

const makeStore = (initialState = {}) => {
    const actions = [];
    const reducer = (state = { posAllProducts: [], ...initialState }, action) => {
        actions.push(action);
        return state;
    };
    const store = createStore(reducer);
    store.recordedActions = actions;
    return store;
};

describe("Phase B — Barcode Options (barcode.json)", () => {
    test("exposes all 5 supported barcode symbologies (1..5)", () => {
        expect(barcodeOptions).toEqual([
            { label: "Code 128", value: "1" },
            { label: "Code 39", value: "2" },
            { label: "EAN-8", value: "3" },
            { label: "UPC-A", value: "4" },
            { label: "EAN-13", value: "5" },
        ]);
    });
});

describe("Phase B — PrintTable Component", () => {
    const Harness = ({ initialProducts, warehouseId = 339 }) => {
        const [products, setProducts] = useState(initialProducts);
        return (
            <table>
                <PrintTable
                    updateProducts={products}
                    setUpdateProducts={setProducts}
                    printBarcodeValue={{ warehouse_id: warehouseId }}
                />
            </table>
        );
    };

    test("updates per-row quantities independently without overwriting other rows (BAR-07)", () => {
        const store = makeStore();
        render(
            <Provider store={store}>
                <Harness
                    initialProducts={[
                        { id: 312, code: "FMC-001", name: "Miniket Rice 1kg", quantity: 10 },
                        { id: 313, code: "FMC-002", name: "Miniket Rice 5kg", quantity: 10, variation_name: "5kg Bag" },
                    ]}
                />
            </Provider>
        );

        const qtyInputs = screen.getAllByLabelText("Product Quantity");
        expect(qtyInputs).toHaveLength(2);
        expect(screen.getByText("5kg Bag")).toBeInTheDocument();

        fireEvent.change(qtyInputs[0], { target: { value: "3" } });
        fireEvent.change(qtyInputs[1], { target: { value: "5" } });

        expect(qtyInputs[0].value).toBe("3");
        expect(qtyInputs[1].value).toBe("5");
    });

    test("clamps empty quantity back to 1 on blur and removes only clicked row on delete", () => {
        const store = makeStore();
        render(
            <Provider store={store}>
                <Harness
                    initialProducts={[
                        { id: 312, code: "FMC-001", name: "Miniket Rice 1kg", quantity: 4 },
                        { id: 313, code: "FMC-002", name: "Miniket Rice 5kg", quantity: 2 },
                    ]}
                />
            </Provider>
        );

        const qtyInputs = screen.getAllByLabelText("Product Quantity");
        fireEvent.change(qtyInputs[0], { target: { value: "" } });
        expect(qtyInputs[0].value).toBe("");
        fireEvent.blur(qtyInputs[0]);
        expect(qtyInputs[0].value).toBe("1");

        const removeButtons = screen.getAllByLabelText("Remove Product");
        fireEvent.click(removeButtons[1]);

        expect(screen.getByText("FMC-001")).toBeInTheDocument();
        expect(screen.queryByText("FMC-002")).not.toBeInTheDocument();
    });
});

describe("Phase B — POS ProductSearchbar Component", () => {
    const sampleProductInMemory = {
        id: 312,
        attributes: {
            name: "Miniket Rice 1kg",
            code: "FMC-001",
            product_code: "894100001",
            product_cost: 60,
            product_price: 78,
            tax_type: 1,
            order_tax: 0,
            stock_alert: 5,
            product_unit: "1",
            sale_unit: "1",
            sale_unit_name: { short_name: "kg" },
            quantity_limit: null,
            stock: { quantity: 10 },
        },
    };

    const sampleOutOfStockNull = {
        id: 340,
        attributes: {
            name: "Soybean Oil 2L",
            code: "FMC-029",
            product_code: "894100029",
            product_cost: 100,
            product_price: 120,
            tax_type: 1,
            order_tax: 0,
            stock: null,
        },
    };

    const sampleServerProduct = {
        id: 375,
        attributes: {
            name: "Farm Brown Eggs 12 pcs",
            code: "FMC-064",
            product_code: "894100064",
            product_cost: 120,
            product_price: 160,
            tax_type: 1,
            order_tax: 0,
            stock_alert: 5,
            product_unit: "1",
            sale_unit: "1",
            sale_unit_name: { short_name: "pc" },
            quantity_limit: null,
            stock: { quantity: 74 },
        },
    };

    const PosHarness = ({ store, onSearchProduct = jest.fn() }) => {
        const [cart, setCart] = useState([]);
        return (
            <Provider store={store}>
                <ProductSearchbar
                    customCart={[]}
                    updateProducts={cart}
                    setUpdateProducts={setCart}
                    selectedOption={{ value: 339, label: "Central Main Warehouse" }}
                    onSearchProduct={onSearchProduct}
                    settings={{ attributes: { show_pos_stock_product: "true" } }}
                />
                <div data-testid="cart-state">{JSON.stringify(cart)}</div>
            </Provider>
        );
    };

    beforeEach(() => {
        jest.clearAllMocks();
    });

    test("adds in-memory product once (no +2 double-add) and increments on repeated scan", () => {
        const store = makeStore({ posAllProducts: [sampleProductInMemory, sampleOutOfStockNull] });
        render(<PosHarness store={store} />);

        const input = screen.getByPlaceholderText("pos-globally.search.field.label");

        // First scan + Enter
        fireEvent.change(input, { target: { value: "FMC-001" } });
        fireEvent.keyDown(input, { key: "Enter" });

        let cart = JSON.parse(screen.getByTestId("cart-state").textContent);
        expect(cart).toHaveLength(1);
        expect(cart[0].code).toBe("FMC-001");
        expect(cart[0].quantity).toBe(1);

        // Second scan + Enter -> increments 1 -> 2
        fireEvent.change(input, { target: { value: "FMC-001" } });
        fireEvent.keyDown(input, { key: "Enter" });

        cart = JSON.parse(screen.getByTestId("cart-state").textContent);
        expect(cart).toHaveLength(1);
        expect(cart[0].quantity).toBe(2);
    });

    test("handles keyboard-wedge Tab suffix via exact GET products/by-barcode for product_code beyond first 30", async () => {
        apiConfig.get.mockResolvedValueOnce({ data: { data: sampleServerProduct } });
        const store = makeStore({ posAllProducts: [sampleProductInMemory] });
        render(<PosHarness store={store} />);

        const input = screen.getByPlaceholderText("pos-globally.search.field.label");
        fireEvent.change(input, { target: { value: "894100064" } });
        fireEvent.keyDown(input, { key: "Tab" });

        await waitFor(() => {
            expect(apiConfig.get).toHaveBeenCalledWith(
                "products/by-barcode?barcode=894100064&warehouse_id=339"
            );
            const cart = JSON.parse(screen.getByTestId("cart-state").textContent);
            expect(cart).toHaveLength(1);
            expect(cart[0].id).toBe(375);
            expect(cart[0].code).toBe("FMC-064");
            expect(cart[0].quantity).toBe(1);
        });
    });

    test("handles null/zero stock safely without crashing and shows error toast for unknown barcode", async () => {
        apiConfig.get.mockRejectedValueOnce({
            response: { data: { message: "Product not found for barcode." } },
        });
        const store = makeStore({ posAllProducts: [sampleOutOfStockNull] });
        render(<PosHarness store={store} />);

        const input = screen.getByPlaceholderText("pos-globally.search.field.label");

        // Null-stock product must NOT crash or add to cart
        fireEvent.change(input, { target: { value: "FMC-029" } });
        fireEvent.keyDown(input, { key: "Enter" });

        let cart = JSON.parse(screen.getByTestId("cart-state").textContent);
        expect(cart).toHaveLength(0);
        expect(store.recordedActions.some((a) => a.type === "ADD_TOAST")).toBe(true);

        // Unknown barcode -> 404 toast & input cleared
        fireEvent.change(input, { target: { value: "UNKNOWN-BARCODE-999999" } });
        fireEvent.keyDown(input, { key: "Enter" });

        await waitFor(() => {
            expect(apiConfig.get).toHaveBeenCalledWith(
                "products/by-barcode?barcode=UNKNOWN-BARCODE-999999&warehouse_id=339"
            );
            expect(input.value).toBe("");
        });
    });

    test("prevents duplicate add when async Redux posAllProducts resolves after exact server lookup", async () => {
        apiConfig.get.mockResolvedValueOnce({ data: { data: sampleServerProduct } });
        const reducer = (state = { posAllProducts: [sampleProductInMemory] }, action) => {
            if (action.type === "SET_POS_PRODUCTS") {
                return { ...state, posAllProducts: action.payload };
            }
            return state;
        };
        const store = createStore(reducer);
        render(<PosHarness store={store} />);

        const input = screen.getByPlaceholderText("pos-globally.search.field.label");
        // Typing triggers onSearchProduct + sets lastScannedCode; Tab triggers handleExactBarcodeScan
        fireEvent.change(input, { target: { value: "894100064" } });
        fireEvent.keyDown(input, { key: "Tab" });

        await waitFor(() => {
            const cart = JSON.parse(screen.getByTestId("cart-state").textContent);
            expect(cart).toHaveLength(1);
            expect(cart[0].id).toBe(375);
            expect(cart[0].quantity).toBe(1);
        });

        // Simulate late Redux posAllProducts response arriving after exact lookup already added the item
        store.dispatch({
            type: "SET_POS_PRODUCTS",
            payload: [sampleProductInMemory, sampleServerProduct],
        });

        await waitFor(() => {
            const cart = JSON.parse(screen.getByTestId("cart-state").textContent);
            expect(cart).toHaveLength(1);
            expect(cart[0].quantity).toBe(1);
        });
    });

    test("discards stale out-of-order server scan response when superseded by a newer scan", async () => {
        let resolveSlowFirst;
        const slowFirstPromise = new Promise((resolve) => {
            resolveSlowFirst = resolve;
        });

        const secondServerProduct = {
            ...sampleServerProduct,
            id: 376,
            attributes: {
                ...sampleServerProduct.attributes,
                name: "Organic Honey 500g",
                code: "FMC-065",
                product_code: "894100065",
            },
        };

        apiConfig.get
            .mockReturnValueOnce(slowFirstPromise)
            .mockResolvedValueOnce({ data: { data: secondServerProduct } });

        const store = makeStore({ posAllProducts: [sampleProductInMemory] });
        render(<PosHarness store={store} />);

        const input = screen.getByPlaceholderText("pos-globally.search.field.label");

        // Scan 1 (slow)
        fireEvent.change(input, { target: { value: "894100064" } });
        fireEvent.keyDown(input, { key: "Enter" });

        // Scan 2 (fast) supersedes Scan 1
        fireEvent.change(input, { target: { value: "894100065" } });
        fireEvent.keyDown(input, { key: "Enter" });

        await waitFor(() => {
            const cart = JSON.parse(screen.getByTestId("cart-state").textContent);
            expect(cart).toHaveLength(1);
            expect(cart[0].id).toBe(376);
            expect(cart[0].code).toBe("FMC-065");
        });

        // Now resolve stale Scan 1 — must be discarded and NOT added to cart
        resolveSlowFirst({ data: { data: sampleServerProduct } });
        await new Promise((r) => setTimeout(r, 20));

        const cartAfterStale = JSON.parse(screen.getByTestId("cart-state").textContent);
        expect(cartAfterStale).toHaveLength(1);
        expect(cartAfterStale[0].id).toBe(376);
    });
});

describe("Phase B — PrintButton Custom Size & Print CSS Scoping", () => {
    const samplePrintProduct = {
        id: 312,
        name: "Miniket Rice 1kg",
        code: "FMC-001",
        product_price: 78,
        quantity: 2,
        barcode_url: "http://127.0.0.1:8000/uploads/product_barcode/barcode-PR_312.png?v=1",
    };

    test("respects custom paperSize=10 dimensions and scopes print CSS so default 2x1in rules cannot override", () => {
        const { container } = render(
            <PrintButton
                updateProducts={{
                    products: [samplePrintProduct],
                    paperSize: { value: 10 },
                }}
                customSize={{ width: 80, height: 40 }}
                frontSetting={{ value: { currency_symbol: "$" } }}
                allConfigData={{ store_name: "FreshMart" }}
                barcodeOptions={{ companyName: true, productName: true, price: true, showBorder: true }}
                priceFontSize={12}
            />
        );

        const expectedWidth = `${(80 * 0.0393701).toFixed(3)}in`;
        const expectedHeight = `${(40 * 0.0393701).toFixed(3)}in`;

        const customItems = container.querySelectorAll(".thermal-barcode-item.thermal-barcode-item--custom");
        expect(customItems).toHaveLength(2);
        expect(customItems[0].style.width).toBe(expectedWidth);
        expect(customItems[0].style.height).toBe(expectedHeight);

        const styleTag = container.querySelector("style");
        expect(styleTag).not.toBeNull();
        expect(styleTag.textContent).toContain(`width: ${expectedWidth} !important;`);
        expect(styleTag.textContent).toContain(`height: ${expectedHeight} !important;`);
        expect(styleTag.textContent).toContain(`size: ${expectedWidth} ${expectedHeight};`);

        // Verify _barcode.scss scopes default 2x1in @media print rules away from custom items
        const scssPath = path.resolve(__dirname, "../assets/scss/custom/pages/_barcode.scss");
        const scssContent = fs.readFileSync(scssPath, "utf8");
        expect(scssContent).toContain(".thermal-barcode-item:not(.thermal-barcode-item--custom)");
    });

    test("preserves default thermal paperSize=9 rendering without custom modifier class", () => {
        const { container } = render(
            <PrintButton
                updateProducts={{
                    products: [samplePrintProduct],
                    paperSize: { value: 9 },
                }}
                frontSetting={{ value: { currency_symbol: "$" } }}
                allConfigData={{ store_name: "FreshMart" }}
                barcodeOptions={{ companyName: true, productName: true, price: true, showBorder: false }}
                priceFontSize={12}
            />
        );

        const thermalItems = container.querySelectorAll(".thermal-barcode-item");
        expect(thermalItems).toHaveLength(2);
        expect(container.querySelectorAll(".thermal-barcode-item--custom")).toHaveLength(0);
    });
});
