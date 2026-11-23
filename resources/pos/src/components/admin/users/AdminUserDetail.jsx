import moment from 'moment';
import React, { useEffect, useState } from 'react';
import { Card, Table } from 'react-bootstrap';
import { Image } from 'react-bootstrap-v5';
import { connect, useDispatch } from 'react-redux';
import { useParams } from 'react-router-dom';
import apiConfig from '../../../config/apiConfig';
import { toastType } from '../../../constants';
import Spinner from "../../../shared/components/loaders/Spinner";
import TopProgressBar from "../../../shared/components/loaders/TopProgressBar";
import { getAvatarName, getFormattedMessage, placeholderText } from '../../../shared/sharedMethod';
import TabTitle from '../../../shared/tab-title/TabTitle';
import { fetchUser } from '../../../store/action/admin/adminUsersAction';
import { addToast } from '../../../store/action/toastAction';
import HeaderTitle from '../../header/HeaderTitle';
import MasterLayout from '../../MasterLayout';

const UserDetail = (props) => {
    const { adminUsers, isLoading, fetchUser } = props;
    const { id } = useParams();
    const dispatch = useDispatch();
    const result = adminUsers?.length > 0 && adminUsers?.reduce((obj, cur) => ({ ...obj, [cur.type]: cur }), {});
    const user = result.users;
    const limitSummary = user?.attributes?.store_limit_summary;

    const [customLimit, setCustomLimit] = useState('');
    const [savingLimit, setSavingLimit] = useState(false);

    useEffect(() => {
        fetchUser(id);
    }, [id]);

    useEffect(() => {
        if (limitSummary?.store_limit_override > 0) {
            setCustomLimit(String(limitSummary.store_limit_override));
        } else {
            setCustomLimit('');
        }
    }, [limitSummary?.store_limit_override]);

    const saveStoreLimitOverride = async (overrideValue) => {
        setSavingLimit(true);
        try {
            const res = await apiConfig.post(`sadmin/admin-users/${id}/store-limit`, {
                store_limit_override: overrideValue,
            });
            dispatch(addToast({ text: res?.data?.message || 'Store creation limit updated.' }));
            fetchUser(id, false);
        } catch ({ response }) {
            dispatch(
                addToast({
                    text: response?.data?.message || 'Failed to update store limit.',
                    type: toastType.ERROR,
                })
            );
        } finally {
            setSavingLimit(false);
        }
    };

    const handleCustomLimitSubmit = (e) => {
        e.preventDefault();
        const parsed = parseInt(customLimit, 10);
        if (!parsed || parsed < 1) {
            dispatch(addToast({ text: 'Please enter a valid positive number (1 or higher).', type: toastType.ERROR }));
            return;
        }
        saveStoreLimitOverride(parsed);
    };

    return (
        <MasterLayout>
            <TopProgressBar />
            <HeaderTitle title={getFormattedMessage('user-details.title')} to='/app/admin/users' editLink={`/app/admin/users/edit/${id}`} />
            <TabTitle title={placeholderText('user-details.title')} />
            {isLoading ? <Spinner /> : <>
                <div>
                    <Card>
                        <Card.Body>
                            <div className='row'>
                                <div className='col-xxl-5 col-12'>
                                    <div
                                        className='d-sm-flex align-items-center mb-5 mb-xxl-0 flex-row text-sm-start'>
                                        <div
                                            className='image image-circle image-lg-small w-100px'>
                                            {user && user.attributes.image ?
                                                <Image src={user.attributes.image} alt='User Profile'
                                                    className='object-fit-cover' /> :
                                                <span className='user_avatar'>
                                                    {getAvatarName(user && user.attributes.first_name + ' ' + user.attributes.last_name)}
                                                </span>
                                            }
                                        </div>
                                        <div className='ms-0 ms-md-10 mt-5 mt-sm-0'>
                                            <span className={`badge ${user && user.attributes.status ? 'bg-light-success' : 'bg-light-danger'} mb-2`}>{user && user.attributes.status ? getFormattedMessage("active.status.lable") : getFormattedMessage("in-active.status.lable")}</span>
                                            <h2> {user && user.attributes.first_name + ' ' + user.attributes.last_name}</h2>
                                            <a href={user && user.attributes.email}
                                                className='text-gray-600 text-decoration-none fs-4'>
                                                {user && user.attributes.email}
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </Card.Body>
                    </Card>
                </div>
                <div className='pt-5'>
                    <Card>
                        <Card.Header as='h5'>{getFormattedMessage('user-details.table.title')}</Card.Header>
                        <Card.Body className='pt-0'>
                            <Table responsive>
                                <tbody>
                                    <tr>
                                        <td className='py-4'>{getFormattedMessage('user.input.first-name.label')}</td>
                                        <td className='py-4'>{user && user.attributes.first_name}</td>
                                    </tr>
                                    <tr>
                                        <td className='py-4'>{getFormattedMessage('user.input.last-name.label')}</td>
                                        <td className='py-4'>{user && user.attributes.last_name}</td>
                                    </tr>
                                    <tr>
                                        <td className='py-4'>{getFormattedMessage('globally.input.email.label')}</td>
                                        <td className='py-4'>{user && user.attributes.email}</td>
                                    </tr>
                                    <tr>
                                        <td className='py-4'>{getFormattedMessage('globally.input.phone-number.label')}</td>
                                        <td className='py-4'>{user && user.attributes.phone}</td>
                                    </tr>
                                    <tr>
                                        <td className='py-4'>{getFormattedMessage('globally.react-table.column.created-date.label')}</td>
                                        <td className='py-4'>{moment(user && user.attributes.created_at).fromNow()}</td>
                                    </tr>
                                </tbody>
                            </Table>
                        </Card.Body>
                    </Card>
                </div>

                {limitSummary && (
                    <div className='pt-5'>
                        <Card>
                            <Card.Header as='h5'>Store Creation Limit</Card.Header>
                            <Card.Body>
                                <Table responsive className='mb-4'>
                                    <tbody>
                                        <tr>
                                            <td className='py-3 fw-semibold'>Subscription Plan</td>
                                            <td className='py-3'>{limitSummary.plan_name || 'N/A'}</td>
                                        </tr>
                                        <tr>
                                            <td className='py-3 fw-semibold'>Plan Store Limit</td>
                                            <td className='py-3'>
                                                {limitSummary.plan_store_limit === -1 ? 'Unlimited' : limitSummary.plan_store_limit}
                                            </td>
                                        </tr>
                                        <tr>
                                            <td className='py-3 fw-semibold'>Custom Store Limit Override</td>
                                            <td className='py-3'>
                                                {limitSummary.store_limit_override === null
                                                    ? <span className='badge bg-light-primary'>Using Plan Limit</span>
                                                    : limitSummary.store_limit_override === -1
                                                        ? <span className='badge bg-success'>Unlimited (Override)</span>
                                                        : <span className='badge bg-primary'>{limitSummary.store_limit_override} Stores (Override)</span>}
                                            </td>
                                        </tr>
                                        <tr>
                                            <td className='py-3 fw-semibold'>Current Active Store Count</td>
                                            <td className='py-3'>{limitSummary.active_store_count} (Total: {limitSummary.total_store_count})</td>
                                        </tr>
                                        <tr>
                                            <td className='py-3 fw-semibold'>Remaining Store Slots</td>
                                            <td className='py-3'>
                                                {limitSummary.is_unlimited ? 'Unlimited' : limitSummary.remaining_store_slots}
                                            </td>
                                        </tr>
                                    </tbody>
                                </Table>

                                <form onSubmit={handleCustomLimitSubmit} className='row g-3 align-items-end'>
                                    <div className='col-md-4'>
                                        <label className='form-label fw-semibold'>Set Custom Store Limit</label>
                                        <input
                                            type='number'
                                            min='1'
                                            max='10000'
                                            className='form-control'
                                            placeholder='e.g. 3 or 5'
                                            value={customLimit}
                                            onChange={(e) => setCustomLimit(e.target.value)}
                                        />
                                    </div>
                                    <div className='col-md-8 d-flex flex-wrap gap-2'>
                                        <button type='submit' className='btn btn-primary' disabled={savingLimit}>
                                            Save Custom Limit
                                        </button>
                                        <button
                                            type='button'
                                            className='btn btn-outline-primary'
                                            disabled={savingLimit}
                                            onClick={() => saveStoreLimitOverride(-1)}
                                        >
                                            Enable Unlimited Stores
                                        </button>
                                        {limitSummary.store_limit_override !== null && (
                                            <button
                                                type='button'
                                                className='btn btn-secondary'
                                                disabled={savingLimit}
                                                onClick={() => saveStoreLimitOverride(null)}
                                            >
                                                Remove Override (Use Plan Limit)
                                            </button>
                                        )}
                                    </div>
                                </form>
                            </Card.Body>
                        </Card>
                    </div>
                )}
            </>
            }
        </MasterLayout>
    )
};

const mapStateToProps = state => {
    const { adminUsers, isLoading } = state;
    return { adminUsers, isLoading }
};

export default connect(mapStateToProps, { fetchUser })(UserDetail);
