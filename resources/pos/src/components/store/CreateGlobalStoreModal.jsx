import React, { useEffect, useState } from "react";
import { Modal } from "react-bootstrap";
import { useDispatch } from "react-redux";
import apiConfig from "../../config/apiConfig";
import { adminApiBaseURL, toastType } from "../../constants";
import { addToast } from "../../store/action/toastAction";
import { fetchAdminUsers } from "../../store/action/admin/adminUsersAction";

const initialForm = {
    store_name: "",
    store_code: "",
    store_email: "",
    store_phone: "",
    store_address: "",
    country: "Bangladesh",
    currency: "",
    plan_id: "",
    first_name: "",
    last_name: "",
    email: "",
    phone: "",
    password: "",
    confirm_password: "",
};

const CreateGlobalStoreModal = ({ show, handleClose }) => {
    const dispatch = useDispatch();
    const [form, setForm] = useState(initialForm);
    const [plans, setPlans] = useState([]);
    const [currencies, setCurrencies] = useState([]);
    const [submitting, setSubmitting] = useState(false);

    useEffect(() => {
        if (!show) return;
        apiConfig
            .get(adminApiBaseURL.PLANS)
            .then((res) => setPlans(res?.data?.data || []))
            .catch(() => {});
        apiConfig
            .get("sadmin/currencies")
            .then((res) => setCurrencies(res?.data?.data || []))
            .catch(() => {});
    }, [show]);

    const onChange = (e) => {
        setForm((prev) => ({ ...prev, [e.target.name]: e.target.value }));
    };

    const onSubmit = async (e) => {
        e.preventDefault();
        setSubmitting(true);
        try {
            await apiConfig.post(adminApiBaseURL.ADMIN_USERS, form);
            dispatch(addToast({ text: "Store and Store Admin created successfully." }));
            dispatch(fetchAdminUsers({}, false));
            setForm(initialForm);
            handleClose();
        } catch ({ response }) {
            dispatch(
                addToast({
                    text: response?.data?.message || "Failed to create store.",
                    type: toastType.ERROR,
                })
            );
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <Modal show={show} onHide={handleClose} size="lg" centered>
            <Modal.Header closeButton>
                <Modal.Title>Create Store &amp; Store Admin</Modal.Title>
            </Modal.Header>
            <form onSubmit={onSubmit}>
                <Modal.Body>
                    <h6 className="fw-semibold mb-3 text-primary">Store Details</h6>
                    <div className="row g-3 mb-4">
                        <div className="col-md-6">
                            <label className="form-label">Store Name <span className="required" /></label>
                            <input type="text" name="store_name" className="form-control" required value={form.store_name} onChange={onChange} placeholder="e.g. Dhaka Flagship Store" />
                        </div>
                        <div className="col-md-6">
                            <label className="form-label">Store Code / Slug</label>
                            <input type="text" name="store_code" className="form-control" value={form.store_code} onChange={onChange} placeholder="e.g. dhaka-flagship" />
                        </div>
                        <div className="col-md-6">
                            <label className="form-label">Store Email</label>
                            <input type="email" name="store_email" className="form-control" value={form.store_email} onChange={onChange} placeholder="store@example.com" />
                        </div>
                        <div className="col-md-6">
                            <label className="form-label">Store Phone</label>
                            <input type="text" name="store_phone" className="form-control" value={form.store_phone} onChange={onChange} placeholder="01749-560393" />
                        </div>
                        <div className="col-md-12">
                            <label className="form-label">Store Address</label>
                            <input type="text" name="store_address" className="form-control" value={form.store_address} onChange={onChange} placeholder="Mirpur, Kushtia, Bangladesh - 7030" />
                        </div>
                        <div className="col-md-4">
                            <label className="form-label">Country</label>
                            <input type="text" name="country" className="form-control" value={form.country} onChange={onChange} />
                        </div>
                        <div className="col-md-4">
                            <label className="form-label">Currency</label>
                            <select name="currency" className="form-select" value={form.currency} onChange={onChange}>
                                <option value="">Default (USD)</option>
                                {currencies.map((c) => (
                                    <option key={c.id} value={c.id}>
                                        {c.attributes?.code} ({c.attributes?.symbol})
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div className="col-md-4">
                            <label className="form-label">Subscription Plan</label>
                            <select name="plan_id" className="form-select" value={form.plan_id} onChange={onChange}>
                                <option value="">Default Plan</option>
                                {plans.map((p) => (
                                    <option key={p.id} value={p.id}>
                                        {p.attributes?.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                    </div>

                    <h6 className="fw-semibold mb-3 text-primary">Store Admin Account</h6>
                    <div className="row g-3">
                        <div className="col-md-6">
                            <label className="form-label">First Name <span className="required" /></label>
                            <input type="text" name="first_name" className="form-control" required value={form.first_name} onChange={onChange} placeholder="Store Admin First Name" />
                        </div>
                        <div className="col-md-6">
                            <label className="form-label">Last Name <span className="required" /></label>
                            <input type="text" name="last_name" className="form-control" required value={form.last_name} onChange={onChange} placeholder="Store Admin Last Name" />
                        </div>
                        <div className="col-md-6">
                            <label className="form-label">Store Admin Email <span className="required" /></label>
                            <input type="email" name="email" className="form-control" required value={form.email} onChange={onChange} placeholder="owner@example.com" />
                        </div>
                        <div className="col-md-6">
                            <label className="form-label">Store Admin Phone (digits) <span className="required" /></label>
                            <input type="text" name="phone" className="form-control" required value={form.phone} onChange={onChange} placeholder="8801749560393" />
                        </div>
                        <div className="col-md-6">
                            <label className="form-label">Password <span className="required" /></label>
                            <input type="password" name="password" className="form-control" required minLength={6} value={form.password} onChange={onChange} placeholder="Minimum 6 characters" />
                        </div>
                        <div className="col-md-6">
                            <label className="form-label">Confirm Password <span className="required" /></label>
                            <input type="password" name="confirm_password" className="form-control" required minLength={6} value={form.confirm_password} onChange={onChange} placeholder="Confirm password" />
                        </div>
                    </div>
                </Modal.Body>
                <Modal.Footer>
                    <button type="button" className="btn btn-secondary" onClick={handleClose}>
                        Cancel
                    </button>
                    <button type="submit" className="btn btn-primary" disabled={submitting}>
                        {submitting ? "Creating..." : "Create Store"}
                    </button>
                </Modal.Footer>
            </form>
        </Modal>
    );
};

export default CreateGlobalStoreModal;
