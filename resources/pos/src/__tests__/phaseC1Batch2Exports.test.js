import fs from "fs";
import path from "path";
import apiConfig from "../config/apiConfig";
import { downloadProtectedFile } from "../shared/downloadProtectedFile";
import { salePdfAction } from "../store/action/salePdfAction";
import { productExcelAction } from "../store/action/productExcelAction";

jest.mock("../config/apiConfig", () => ({
    get: jest.fn(),
}));

describe("Phase C1.4 — Authenticated Protected Export Downloads", () => {
    let originalCreateObjectURL;
    let originalRevokeObjectURL;
    let originalWindowOpen;

    beforeEach(() => {
        jest.clearAllMocks();
        originalCreateObjectURL = window.URL.createObjectURL;
        originalRevokeObjectURL = window.URL.revokeObjectURL;
        originalWindowOpen = window.open;

        window.URL.createObjectURL = jest.fn(() => "blob:http://localhost/fake-export");
        window.URL.revokeObjectURL = jest.fn();
        window.open = jest.fn();
    });

    afterEach(() => {
        window.URL.createObjectURL = originalCreateObjectURL;
        window.URL.revokeObjectURL = originalRevokeObjectURL;
        window.open = originalWindowOpen;
    });

    test("downloadProtectedFile fetches private export via authenticated apiConfig Blob and triggers download", async () => {
        const clickSpy = jest.spyOn(HTMLAnchorElement.prototype, "click").mockImplementation(() => {});

        apiConfig.get.mockResolvedValueOnce({
            data: new Blob(["%PDF-1.4"], { type: "application/pdf" }),
            headers: {
                "content-type": "application/pdf",
                "content-disposition": 'attachment; filename="Sale-123.pdf"',
            },
        });

        const filename = await downloadProtectedFile(
            "http://127.0.0.1:8000/api/private-files/tenantA/exports/pdf/sale-abc.pdf"
        );

        expect(apiConfig.get).toHaveBeenCalledWith(
            "http://127.0.0.1:8000/api/private-files/tenantA/exports/pdf/sale-abc.pdf",
            { responseType: "blob" }
        );
        expect(filename).toBe("Sale-123.pdf");
        expect(window.URL.createObjectURL).toHaveBeenCalledTimes(1);
        expect(clickSpy).toHaveBeenCalledTimes(1);
        expect(window.open).not.toHaveBeenCalled();

        clickSpy.mockRestore();
    });

    test("salePdfAction and productExcelAction use authenticated Blob download instead of window.open", async () => {
        const clickSpy = jest.spyOn(HTMLAnchorElement.prototype, "click").mockImplementation(() => {});
        const dispatch = jest.fn();

        // 1. salePdfAction
        apiConfig.get
            .mockResolvedValueOnce({
                data: { data: { sale_pdf_url: "http://127.0.0.1:8000/api/private-files/tA/exports/pdf/sale-1.pdf" } },
            })
            .mockResolvedValueOnce({
                data: new Blob(["%PDF-1.4"], { type: "application/pdf" }),
                headers: { "content-type": "application/pdf" },
            });

        await salePdfAction(1, true)(dispatch);
        await new Promise((r) => setTimeout(r, 10));

        expect(apiConfig.get).toHaveBeenCalledWith(
            "http://127.0.0.1:8000/api/private-files/tA/exports/pdf/sale-1.pdf",
            { responseType: "blob" }
        );
        expect(window.open).not.toHaveBeenCalled();

        // 2. productExcelAction
        const setWarehouse = jest.fn();
        apiConfig.get
            .mockResolvedValueOnce({
                data: { data: { product_excel_url: "http://127.0.0.1:8000/api/private-files/tA/exports/excel/prod.xlsx" } },
            })
            .mockResolvedValueOnce({
                data: new Blob(["PK"], { type: "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" }),
                headers: { "content-type": "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" },
            });

        await productExcelAction(setWarehouse, true, 5)(dispatch);
        await new Promise((r) => setTimeout(r, 10));

        expect(apiConfig.get).toHaveBeenCalledWith(
            "http://127.0.0.1:8000/api/private-files/tA/exports/excel/prod.xlsx",
            { responseType: "blob" }
        );
        expect(setWarehouse).toHaveBeenCalledWith(false);
        expect(window.open).not.toHaveBeenCalled();

        clickSpy.mockRestore();
    });

    test("no Redux export action files use unauthenticated window.open()", () => {
        const actionDir = path.resolve(__dirname, "../store/action");
        const jsFiles = fs.readdirSync(actionDir).filter((f) => f.endsWith(".js"));
        const offenders = [];

        for (const file of jsFiles) {
            const content = fs.readFileSync(path.join(actionDir, file), "utf8");
            if (content.includes("window.open(")) {
                offenders.push(file);
            }
        }

        expect(offenders).toEqual([]);
    });

    test("B2-04: parses JSON error message from Blob on 401/403/404/500 and handles malformed non-JSON safely", async () => {
        // 403 JSON Blob error
        apiConfig.get.mockRejectedValueOnce({
            response: {
                status: 403,
                data: new Blob([JSON.stringify({ message: "Unauthorized file access." })], {
                    type: "application/json",
                }),
            },
        });
        await expect(
            downloadProtectedFile("http://127.0.0.1:8000/api/private-files/other/file.pdf")
        ).rejects.toMatchObject({
            message: "Unauthorized file access.",
            response: { status: 403, data: { message: "Unauthorized file access." } },
        });

        // 401 expired session with non-JSON HTML Blob -> safe fallback message
        apiConfig.get.mockRejectedValueOnce({
            response: {
                status: 401,
                data: new Blob(["<html>Unauthorized</html>"], { type: "text/html" }),
            },
        });
        await expect(
            downloadProtectedFile("http://127.0.0.1:8000/api/private-files/tA/file.pdf")
        ).rejects.toMatchObject({
            message: "Your session has expired. Please sign in again.",
            response: { status: 401, data: { message: "Your session has expired. Please sign in again." } },
        });

        // 404 missing file with malformed JSON Blob -> safe 404 fallback
        apiConfig.get.mockRejectedValueOnce({
            response: {
                status: 404,
                data: new Blob(["{bad-json"], { type: "application/json" }),
            },
        });
        await expect(
            downloadProtectedFile("http://127.0.0.1:8000/api/private-files/tA/missing.pdf")
        ).rejects.toMatchObject({
            message: "Requested export file was not found.",
            response: { status: 404, data: { message: "Requested export file was not found." } },
        });

        // 500 server error with empty body -> safe generic fallback
        apiConfig.get.mockRejectedValueOnce({
            response: {
                status: 500,
                data: new Blob([""], { type: "text/plain" }),
            },
        });
        await expect(
            downloadProtectedFile("http://127.0.0.1:8000/api/private-files/tA/err.pdf")
        ).rejects.toMatchObject({
            message: "Failed to download export file.",
            response: { status: 500, data: { message: "Failed to download export file." } },
        });
    });

    test("B2-04: salePdfAction resets loading indicator and displays parsed Blob error message on failure", async () => {
        const dispatch = jest.fn();

        apiConfig.get
            .mockResolvedValueOnce({
                data: { data: { sale_pdf_url: "http://127.0.0.1:8000/api/private-files/tA/exports/pdf/sale-1.pdf" } },
            })
            .mockRejectedValueOnce({
                response: {
                    status: 403,
                    data: new Blob([JSON.stringify({ message: "Unauthorized file access." })], {
                        type: "application/json",
                    }),
                },
            });

        await salePdfAction(1, true)(dispatch);
        await new Promise((r) => setTimeout(r, 20));

        expect(dispatch).toHaveBeenCalledWith({ type: "IS_LOADING", payload: true });
        expect(dispatch).toHaveBeenCalledWith({ type: "IS_LOADING", payload: false });
        expect(dispatch).toHaveBeenCalledWith(
            expect.objectContaining({
                type: "ADD_TOAST",
                payload: expect.objectContaining({ text: "Unauthorized file access." }),
            })
        );
    });
});
