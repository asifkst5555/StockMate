import moment from "moment";
import React, { useEffect, useState } from "react";
import { Modal } from "react-bootstrap";
import { connect, useDispatch } from "react-redux";
import { Link, useNavigate } from "react-router-dom";
import apiConfig from "../../../config/apiConfig";
import ActionButton from "../../../shared/action-buttons/ActionButton";
import TopProgressBar from "../../../shared/components/loaders/TopProgressBar";
import {
    getAvatarName,
    getFormattedDate,
    getFormattedMessage,
    placeholderText,
} from "../../../shared/sharedMethod";
import TabTitle from "../../../shared/tab-title/TabTitle";
import ReactDataTable from "../../../shared/table/ReactDataTable";
import { changeUserStatus, fetchAdminUsers, verifyUserEmail } from "../../../store/action/admin/adminUsersAction";
import { addToast } from "../../../store/action/toastAction";
import MasterLayout from "../../MasterLayout";
import DeleteUser from "./DeleteUser";
import ChangeUserPassword from "../../auth/change-password/ChangeUserPassword";
import { ROLES, toastType } from "../../../constants";

const Users = ({ fetchAdminUsers, adminUsers, totalRecord, isLoading, allConfigData }) => {
    const [deleteModel, setDeleteModel] = useState(false);
    const [isDelete, setIsDelete] = useState(null);
    const [isChangePassword, setIsChangePassword] = useState(false);
    const [isUserId, setIsUserId] = useState(null);
    const [showSuperAdminModal, setShowSuperAdminModal] = useState(false);
    const [superAdmins, setSuperAdmins] = useState([]);
    const [saSubmitting, setSaSubmitting] = useState(false);
    const [saForm, setSaForm] = useState({
        first_name: "",
        last_name: "",
        email: "",
        phone: "",
        password: "",
        confirm_password: "",
    });
    const navigate = useNavigate();
    const dispatch = useDispatch();

    const loadSuperAdmins = async () => {
        try {
            const res = await apiConfig.get("sadmin/super-admins");
            setSuperAdmins(res?.data?.data || []);
        } catch ({ response }) {
            dispatch(addToast({ text: response?.data?.message || "Failed to load Super Admins", type: toastType.ERROR }));
        }
    };

    const handleOpenSuperAdminModal = () => {
        setShowSuperAdminModal(true);
        loadSuperAdmins();
    };

    const handleCreateSuperAdmin = async (e) => {
        e.preventDefault();
        setSaSubmitting(true);
        try {
            await apiConfig.post("sadmin/super-admins", saForm);
            dispatch(addToast({ text: "Super Admin created successfully." }));
            setSaForm({ first_name: "", last_name: "", email: "", phone: "", password: "", confirm_password: "" });
            loadSuperAdmins();
        } catch ({ response }) {
            dispatch(addToast({ text: response?.data?.message || "Failed to create Super Admin", type: toastType.ERROR }));
        } finally {
            setSaSubmitting(false);
        }
    };

    const handleToggleSuperAdminStatus = async (id) => {
        try {
            const res = await apiConfig.post(`sadmin/super-admins/${id}/status`);
            dispatch(addToast({ text: res?.data?.message || "Status updated" }));
            loadSuperAdmins();
        } catch ({ response }) {
            dispatch(addToast({ text: response?.data?.message || "Action not allowed", type: toastType.ERROR }));
        }
    };

    const handleDeleteSuperAdmin = async (id) => {
        try {
            const res = await apiConfig.delete(`sadmin/super-admins/${id}`);
            dispatch(addToast({ text: res?.data?.message || "Super Admin deleted" }));
            loadSuperAdmins();
        } catch ({ response }) {
            dispatch(addToast({ text: response?.data?.message || "Action not allowed", type: toastType.ERROR }));
        }
    };

    const onClickDeleteModel = (isDelete = null) => {
        setDeleteModel(!deleteModel);
        setIsDelete(isDelete);
    };

    const onChange = (filter) => {
        fetchAdminUsers(filter, true);
    };

    const goToEditProduct = (item) => {
        const id = item.id;
        navigate(`/app/admin/users/edit/${id}`);
    };
    const [localItemsValue, setLocalItemsValue] = useState([]);

    useEffect(() => {
        if (adminUsers.length >= 0) {
            const mappedUsers = adminUsers.map((user) => ({
                date: getFormattedDate(
                    user.attributes.created_at,
                    allConfigData && allConfigData
                ),
                time: moment(user.attributes.created_at).format("LT"),
                name: user.attributes.name,
                email: user.attributes.email,
                phone: user.attributes.phone,
                id: user.id,
                image: user.attributes.image,
                first_name: user.attributes.first_name,
                last_name: user.attributes.last_name,
                email_verified: user.attributes.email_verified,
                status: user.attributes.status,
                plan: user.attributes.plan,
            }));
            setLocalItemsValue(mappedUsers);
        }
    }, [adminUsers, allConfigData]);

    const handleVerifyEmail = (id) => {
        dispatch(verifyUserEmail(id));

        setLocalItemsValue((prevState) =>
            prevState.map((item) =>
                item.id === id ? { ...item, email_verified: true } : item
            )
        );
    };
    const handleStatusChange = (id, status) => {
        dispatch(changeUserStatus(id));

        setLocalItemsValue((prevState) =>
            prevState.map((item) =>
                item.id === id ? { ...item, status: status } : item
            )
        );
    };

     const goToChangePassword = (item) => {
        setIsChangePassword(true);
        setIsUserId(item);
    }

    const columns = [
        {
            name: getFormattedMessage('users.table.user.column.title'),
            selector: row => row.first_name,
            sortField: 'first_name',
            sortable: true,
            cell: row => {
                const imageUrl = row.image ? row.image : null;
                const lastName = row.last_name ? row.last_name : '';
                return <div className='d-flex align-items-center'>
                    <div className='me-2'>
                        <Link to={`/app/admin/users/detail/${row.id}`}>
                            {imageUrl ?
                                <img src={imageUrl} height='50' width='50' alt='User Image'
                                    className='image image-circle image-mini' /> :
                                <span className='custom-user-avatar fs-5'>
                                    {getAvatarName(row.first_name + ' ' + row.last_name)}
                                </span>
                            }
                        </Link>
                    </div>
                    <div className='d-flex flex-column'>
                        <Link to={`/app/admin/users/detail/${row.id}`} className='text-decoration-none'>{row.first_name + ' ' + lastName}</Link>
                        <span>{row.email}</span>
                    </div>
                </div>
            }
        },
        {
            name: getFormattedMessage("globally.input.phone-number.label"),
            selector: (row) => row.phone,
            sortField: "phone",
            sortable: true,
        },
        {
            name: getFormattedMessage("email-verified.title"),
            selector: (row) => row.email_verified,
            sortable: false,
            cell: row => {
                return <div className='d-flex align-items-center'>
                    <label className="form-check form-switch form-switch-sm">
                        <input type='checkbox' checked={row.email_verified}
                            onChange={() => handleVerifyEmail(row.id)}
                            disabled={row.email_verified}
                            className='me-3 form-check-input cursor-pointer' />
                        <div className='control__indicator' />
                    </label>
                </div>
            }
        },
        {
            name: getFormattedMessage("globally.detail.status"),
            selector: (row) => row.status,
            sortable: false,
            cell: row => {
                return <div className='d-flex align-items-center'>
                    <label className="form-check form-switch form-switch-sm">
                        <input type='checkbox' checked={row.status}
                            onChange={(e) => handleStatusChange(row.id, e.target.checked)}
                            className='me-3 form-check-input cursor-pointer' />
                        <div className='control__indicator' />
                    </label>
                </div>
            }
        },
        {
            name: getFormattedMessage(
                "plan.title"
            ),
            selector: (row) => row.plan,
            sortField: "plan",
            sortable: false,
        },
        {
            name: getFormattedMessage(
                "globally.react-table.column.created-date.label"
            ),
            selector: (row) => row.date,
            sortField: "created_at",
            sortable: true,
            cell: (row) => {
                return (
                    <span className="badge bg-light-info">
                        <div className="mb-1">{row.time}</div>
                        {row.date}
                    </span>
                );
            },
        },
        {
            name: getFormattedMessage("react-data-table.action.column.label"),
            right: true,
            ignoreRowClick: true,
            allowOverflow: true,
            button: true,
            cell: (row) => (
                <ActionButton
                    item={row}
                    isPasswordShow={true}
                    goToChangePassword={goToChangePassword}
                    goToEditProduct={goToEditProduct}
                    isEditMode={true}
                    onClickDeleteModel={onClickDeleteModel}
                />
            ),
        },
    ];

    return (
        <MasterLayout>
            <TopProgressBar />
            <TabTitle title={placeholderText("users.title")} />
            <div className="d-flex justify-content-end mb-3">
                <button
                    type="button"
                    className="btn btn-outline-primary"
                    onClick={handleOpenSuperAdminModal}
                >
                    Manage Super Admins
                </button>
            </div>
            <ReactDataTable
                buttonValue={getFormattedMessage('user.create.title')}
                to='/app/admin/users/create'
                columns={columns}
                items={localItemsValue}
                onChange={onChange}
                isLoading={isLoading}
                totalRows={totalRecord}
            />
            <DeleteUser onClickDeleteModel={onClickDeleteModel} deleteModel={deleteModel} onDelete={isDelete} />

            <Modal show={showSuperAdminModal} onHide={() => setShowSuperAdminModal(false)} size="lg" centered>
                <Modal.Header closeButton>
                    <Modal.Title>Super Admin Accounts</Modal.Title>
                </Modal.Header>
                <Modal.Body>
                    <form onSubmit={handleCreateSuperAdmin} className="mb-4 pb-3 border-bottom">
                        <h6 className="mb-3 fw-semibold">Create New Super Admin</h6>
                        <div className="row g-3">
                            <div className="col-md-6">
                                <input
                                    type="text"
                                    className="form-control"
                                    placeholder="First Name"
                                    required
                                    value={saForm.first_name}
                                    onChange={(e) => setSaForm({ ...saForm, first_name: e.target.value })}
                                />
                            </div>
                            <div className="col-md-6">
                                <input
                                    type="text"
                                    className="form-control"
                                    placeholder="Last Name"
                                    required
                                    value={saForm.last_name}
                                    onChange={(e) => setSaForm({ ...saForm, last_name: e.target.value })}
                                />
                            </div>
                            <div className="col-md-6">
                                <input
                                    type="email"
                                    className="form-control"
                                    placeholder="Email Address"
                                    required
                                    value={saForm.email}
                                    onChange={(e) => setSaForm({ ...saForm, email: e.target.value })}
                                />
                            </div>
                            <div className="col-md-6">
                                <input
                                    type="text"
                                    className="form-control"
                                    placeholder="Phone Number (digits)"
                                    required
                                    value={saForm.phone}
                                    onChange={(e) => setSaForm({ ...saForm, phone: e.target.value })}
                                />
                            </div>
                            <div className="col-md-6">
                                <input
                                    type="password"
                                    className="form-control"
                                    placeholder="Password (min 6 chars)"
                                    required
                                    minLength={6}
                                    value={saForm.password}
                                    onChange={(e) => setSaForm({ ...saForm, password: e.target.value })}
                                />
                            </div>
                            <div className="col-md-6">
                                <input
                                    type="password"
                                    className="form-control"
                                    placeholder="Confirm Password"
                                    required
                                    minLength={6}
                                    value={saForm.confirm_password}
                                    onChange={(e) => setSaForm({ ...saForm, confirm_password: e.target.value })}
                                />
                            </div>
                            <div className="col-12 d-flex justify-content-end">
                                <button type="submit" className="btn btn-primary" disabled={saSubmitting}>
                                    {saSubmitting ? "Creating..." : "Create Super Admin"}
                                </button>
                            </div>
                        </div>
                    </form>
                    <h6 className="mb-3 fw-semibold">Existing Super Admins ({superAdmins.length})</h6>
                    <div className="table-responsive">
                        <table className="table table-sm align-middle">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Status</th>
                                    <th className="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {superAdmins.map((sa) => (
                                    <tr key={sa.id}>
                                        <td>{sa.attributes?.first_name} {sa.attributes?.last_name}</td>
                                        <td>{sa.attributes?.email}</td>
                                        <td>
                                            <span className={`badge ${sa.attributes?.status ? "bg-success" : "bg-secondary"}`}>
                                                {sa.attributes?.status ? "Active" : "Inactive"}
                                            </span>
                                        </td>
                                        <td className="text-end">
                                            <button
                                                type="button"
                                                className="btn btn-sm btn-outline-primary me-2"
                                                onClick={() => handleToggleSuperAdminStatus(sa.id)}
                                            >
                                                Toggle Status
                                            </button>
                                            <button
                                                type="button"
                                                className="btn btn-sm btn-outline-danger"
                                                onClick={() => handleDeleteSuperAdmin(sa.id)}
                                            >
                                                Delete
                                            </button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </Modal.Body>
            </Modal>

            {isChangePassword && (
                <ChangeUserPassword
                    handleClose={() => setIsChangePassword(false)}
                    show={isChangePassword}
                    userDetails={isUserId}
                    role={ROLES.SUPER_ADMIN}
                />
            )}
        </MasterLayout>
    );
};

const mapStateToProps = (state) => {
    const { adminUsers, totalRecord, isLoading, allConfigData } = state;
    return { adminUsers, totalRecord, isLoading, allConfigData };
};

export default connect(mapStateToProps, { fetchAdminUsers })(Users);
