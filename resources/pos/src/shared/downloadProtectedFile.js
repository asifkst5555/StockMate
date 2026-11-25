import apiConfig from "../config/apiConfig";

const readBlobAsText = (blob) => {
    if (!blob) {
        return Promise.resolve("");
    }
    if (typeof blob.text === "function") {
        return blob.text();
    }
    return new Promise((resolve) => {
        if (typeof FileReader === "undefined") {
            resolve("");
            return;
        }
        const reader = new FileReader();
        reader.onload = () => resolve(typeof reader.result === "string" ? reader.result : "");
        reader.onerror = () => resolve("");
        reader.readAsText(blob);
    });
};

const defaultErrorMessageForStatus = (status) => {
    if (status === 401) {
        return "Your session has expired. Please sign in again.";
    }
    if (status === 403) {
        return "You are not authorized to download this export.";
    }
    if (status === 404) {
        return "Requested export file was not found.";
    }
    return "Failed to download export file.";
};

/**
 * Normalize Axios errors when responseType='blob' returns a JSON error payload inside a Blob.
 */
const normalizeBlobDownloadError = async (error) => {
    const status = error?.response?.status;
    const rawData = error?.response?.data;
    let message = defaultErrorMessageForStatus(status);

    if (rawData && typeof rawData === "object" && typeof rawData.message === "string" && rawData.message.trim() !== "") {
        message = rawData.message.trim();
    } else if (rawData instanceof Blob || (rawData && typeof rawData.text === "function")) {
        try {
            const text = await readBlobAsText(rawData);
            if (text && text.trim() !== "") {
                const parsed = JSON.parse(text);
                if (parsed && typeof parsed.message === "string" && parsed.message.trim() !== "") {
                    message = parsed.message.trim();
                }
            }
        } catch (_e) {
            // Malformed or non-JSON error blob — retain safe fallback message
        }
    } else if (typeof rawData === "string" && rawData.trim() !== "") {
        try {
            const parsed = JSON.parse(rawData);
            if (parsed && typeof parsed.message === "string" && parsed.message.trim() !== "") {
                message = parsed.message.trim();
            }
        } catch (_e) {
            // Non-JSON string — retain safe fallback message
        }
    }

    const normalized = new Error(message);
    normalized.response = {
        ...(error?.response || {}),
        status,
        data: { message },
    };

    return normalized;
};

/**
 * Download a tenant-isolated protected export (/api/private-files/...)
 * using the authenticated Axios client (Sanctum Bearer token) as a Blob.
 *
 * Never uses unauthenticated window.open() on protected API URLs.
 */
export const downloadProtectedFile = async (fileUrl, fallbackFilename = "export") => {
    if (!fileUrl) {
        const err = new Error("Export file URL is missing.");
        err.response = { data: { message: "Export file URL is missing." } };
        throw err;
    }

    let response;
    try {
        response = await apiConfig.get(fileUrl, {
            responseType: "blob",
        });
    } catch (error) {
        throw await normalizeBlobDownloadError(error);
    }

    const contentDisposition = response?.headers?.["content-disposition"] || "";
    const contentType = response?.headers?.["content-type"] || "application/octet-stream";

    let filename = fallbackFilename;
    const match = contentDisposition.match(/filename\*?=(?:UTF-8'')?["']?([^"';\n]+)["']?/i);
    if (match && match[1]) {
        filename = decodeURIComponent(match[1].trim()).replace(/[/\\]+/g, "_");
    } else {
        try {
            const parsed = new URL(fileUrl, window.location.origin);
            const segment = parsed.pathname.split("/").filter(Boolean).pop();
            if (segment) {
                filename = decodeURIComponent(segment).replace(/[/\\]+/g, "_");
            }
        } catch (_e) {
            // Keep fallbackFilename
        }
    }

    const blob = new Blob([response.data], { type: contentType });
    const blobUrl = window.URL.createObjectURL(blob);

    try {
        const link = document.createElement("a");
        link.href = blobUrl;
        link.setAttribute("download", filename);
        link.style.display = "none";
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    } finally {
        setTimeout(() => {
            window.URL.revokeObjectURL(blobUrl);
        }, 1000);
    }

    return filename;
};

export default downloadProtectedFile;
