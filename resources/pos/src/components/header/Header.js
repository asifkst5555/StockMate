import React, { useEffect, useState } from 'react';
import { Image, Nav, Navbar } from 'react-bootstrap-v5';
import { connect, useSelector } from 'react-redux';
import { Link, useNavigate } from 'react-router-dom';
import { ROLES, Tokens } from '../../constants/index'
import { logoutAction } from '../../store/action/authAction';
import ChangePassword from '../auth/change-password/ChangePassword';
import { getAvatarName } from '../../shared/sharedMethod';
import { getFormattedMessage } from '../../shared/sharedMethod';
import { updateLanguage } from '../../store/action/updateLanguageAction';
import User from '../../assets/images/avatar.png';
import { FontAwesomeIcon } from "@fortawesome/react-fontawesome";
import {
    faMaximize,
    faMinimize,
    faUser,
    faLock,
    faRightFromBracket,
    faAngleDown,
    faLanguage,
    faMoneyBill,
    faCalendarAlt,
    faBell,
    faClock
} from '@fortawesome/free-solid-svg-icons';
import { Dropdown } from "react-bootstrap";
import { productQuantityReportAction } from '../../store/action/paymentQuantityReport';
import LanguageModel from "../user-profile/LanguageModel";
import PosRegisterModel from '../posRegister/PosRegisterModel.js';
import StoreWarningModal from './StoreWarningModal.jsx';
import SubscriptionWarningModal from './SubscriptionWarningModal.jsx';

const Header = (props) => {
    const { logoutAction, newRoutes } = props;
    const navigate = useNavigate();
    const users = localStorage.getItem(Tokens.USER);
    const firstName = localStorage.getItem(Tokens.FIRST_NAME);
    const lastName = localStorage.getItem(Tokens.LAST_NAME);
    const token = localStorage.getItem(Tokens.ADMIN);
    const imageUrl = localStorage.getItem(Tokens.USER_IMAGE_URL);
    const image = localStorage.getItem(Tokens.IMAGE);
    const updatedEmail = localStorage.getItem(Tokens.UPDATED_EMAIL);
    const updatedFirstName = localStorage.getItem(Tokens.UPDATED_FIRST_NAME);
    const updatedLastName = localStorage.getItem(Tokens.UPDATED_LAST_NAME);
    const [deleteModel, setDeleteModel] = useState(false);
    const [languageModel, setLanguageModel] = useState(false);
    const [isFullscreen, setIsFullscreen] = useState(false);
    const [showPosRegisterModel, setShowPosRegisterModel] = useState(false);
    const [storeWarningModal, setStoreWarningModal] = useState(false);
    const [subscriptionWarningModal, setSubscriptionWarningModal] = useState(false);
    const [currentTime, setCurrentTime] = useState(new Date())
    const [avatarLoadFailed, setAvatarLoadFailed] = useState(false);
    const { allConfigData, loginUser, userProfile } = useSelector(state => state);
    const updatedLanguage = localStorage.getItem(Tokens.UPDATED_LANGUAGE);

    const profileAttributes = userProfile?.attributes;
    const avatarSrc = (profileAttributes && profileAttributes.image !== undefined)
        ? (profileAttributes.image || '')
        : (imageUrl || image || loginUser?.user?.image_url || '');

    const displayFirstName = profileAttributes?.first_name || updatedFirstName || firstName || '';
    const displayLastName = profileAttributes?.last_name || updatedLastName || lastName || '';
    const displayFullName = `${displayFirstName} ${displayLastName}`.trim();
    const displayEmail = profileAttributes?.email || updatedEmail || users || '';

    useEffect(() => {
        setAvatarLoadFailed(false);
    }, [avatarSrc]);

    const onClickDeleteModel = () => {
        setDeleteModel(!deleteModel);
    };

    useEffect(() => {
        setStoreWarningModal(loginUser.roles !== ROLES.SUPER_ADMIN && allConfigData?.store_modal);
        setSubscriptionWarningModal(allConfigData?.is_sub_user && allConfigData?.is_expired);
    }, [allConfigData])

    const onClickLanguageModel = () => {
        setLanguageModel(!languageModel);
    };

    useEffect(() => {
        const timer = setInterval(() => {
            setCurrentTime(new Date())
        }, 1000)
        return () => clearInterval(timer)
    }, [])

    const formatTime = (date) => {
        return date.toLocaleTimeString(updatedLanguage == 'gr' ? "de" : updatedLanguage === 'sp' ? "ES" : updatedLanguage === 'cn' ? "zh" : updatedLanguage, {
            hour: '2-digit',
            minute: '2-digit',
            hour12: true
        })
    }

    const formatDate = (date) => {
        return date.toLocaleDateString(updatedLanguage == 'gr' ? "de" : updatedLanguage === 'sp' ? "ES" : updatedLanguage === 'cn' ? "zh" : updatedLanguage, {
            weekday: 'short',
            year: 'numeric',
            month: 'short',
            day: 'numeric'
        })
    }

    const onLogOut = () => {
        logoutAction(token, navigate);
    };
    
    const onClickManageSubscription = () => {
        navigate('/app/user/manage-subscription');
    };

    const onProfileClick = () => {
        navigate(loginUser?.roles == ROLES.SUPER_ADMIN ? '/app/admin/profile/edit' : '/app/user/profile/edit');
    };

    const fullScreen = () => {
        if (!document.fullscreenElement) {
            document.documentElement.requestFullscreen();
            setIsFullscreen(true);
        } else {
            if (document.exitFullscreen) {
                document.exitFullscreen();
                setIsFullscreen(false);
            }
        }
    };

    const handleClickPOSBtn = () => {
        setShowPosRegisterModel(true)
    }

    const onClickshowPosRegisterModel = () => {
        setShowPosRegisterModel(false)
    }

    const handleLogoutButton = () => { 
        logoutAction(token, navigate);
    }

    return (
        <Navbar collapseOnSelect expand='lg' className='align-items-center ms-auto py-1 sm-header-right-nav'>
            <div className='d-flex align-items-center justify-content-end'>
                <Nav className='align-items-center justify-content-between flex-row sm-header-controls'>
                    <ul className='nav align-items-center flex-nowrap sm-header-utility-list'>
                        <li className="sm-nav-divider d-none d-xxl-block" aria-hidden="true" />
                        <li className="sm-header-date-item d-none d-xxl-flex align-items-center">
                            <FontAwesomeIcon icon={faCalendarAlt} className="sm-header-meta-icon" />
                            <span className="sm-header-date-text">{formatDate(currentTime)}</span>
                        </li>
                        <li className="sm-nav-divider d-none d-lg-block" aria-hidden="true" />
                        <li className="sm-header-time-item d-none d-lg-flex align-items-center">
                            <FontAwesomeIcon icon={faClock} className="sm-header-meta-icon" />
                            <span className="sm-header-time-text">{formatTime(currentTime)}</span>
                        </li>
                        <li className="sm-header-pos-item">
                            {loginUser?.roles != ROLES.SUPER_ADMIN && newRoutes.map((route) => route.permission).filter(routeName => routeName === 'manage_pos_screen')[0] === 'manage_pos_screen'
                                ?
                                allConfigData?.open_register === true
                                    ?
                                    <button onClick={handleClickPOSBtn}
                                        className='px-sm-3 px-2 d-flex text-decoration-none pos-button pos-button-highlight sm-pos-btn'>
                                        {getFormattedMessage('header.pos.title')}
                                    </button>
                                    :
                                    <Link to='/app/user/pos'
                                        className='px-sm-3 px-2 d-flex text-decoration-none pos-button pos-button-highlight sm-pos-btn'>
                                        {getFormattedMessage('header.pos.title')}
                                    </Link>
                                :
                                ''
                            }
                        </li>
                        <li className="sm-header-icon-wrap d-none d-md-flex">
                            <button
                                type="button"
                                className="sm-header-icon-btn"
                                onClick={() => fullScreen()}
                                title={isFullscreen ? "Exit fullscreen" : "Fullscreen"}
                                aria-label={isFullscreen ? "Exit fullscreen" : "Fullscreen"}
                            >
                                <FontAwesomeIcon icon={isFullscreen ? faMinimize : faMaximize} />
                            </button>
                        </li>
                        <li className="sm-header-icon-wrap d-none d-sm-flex">
                            <Dropdown align="end">
                                <Dropdown.Toggle
                                    as="button"
                                    type="button"
                                    id="sm-notifications-toggle"
                                    className="sm-header-icon-btn sm-bell-btn"
                                    title="Notifications"
                                    aria-label="Notifications"
                                >
                                    <FontAwesomeIcon icon={faBell} />
                                    <span className="sm-bell-dot" aria-hidden="true" />
                                </Dropdown.Toggle>
                                <Dropdown.Menu className="sm-notif-menu">
                                    <div className="sm-notif-head">Notifications</div>
                                    {loginUser?.roles !== ROLES.SUPER_ADMIN ? (
                                        <>
                                            <Dropdown.Item
                                                as={Link}
                                                to="/app/user/report/report-product-quantity-alert"
                                                className="sm-notif-item"
                                            >
                                                View Low Stock Alerts
                                            </Dropdown.Item>
                                            <Dropdown.Item
                                                as={Link}
                                                to="/app/user/sales"
                                                className="sm-notif-item"
                                            >
                                                View Recent Sales
                                            </Dropdown.Item>
                                        </>
                                    ) : (
                                        <Dropdown.Item
                                            as={Link}
                                            to="/app/admin/dashboard"
                                            className="sm-notif-item"
                                        >
                                            System Overview
                                        </Dropdown.Item>
                                    )}
                                </Dropdown.Menu>
                            </Dropdown>
                        </li>
                    </ul>
                    <Dropdown className='d-flex align-items-stretch'>
                        <Dropdown.Toggle className='hide-arrow bg-transparent border-0 p-0 d-flex align-items-center'
                            id='dropdown-basic'>
                            <div className='d-flex align-items-center justify-content-center'>
                                {avatarSrc && !avatarLoadFailed ?
                                    <Image src={avatarSrc}
                                        onError={() => setAvatarLoadFailed(true)}
                                        className='image image-circle image-tiny'
                                        style={{ objectFit: 'cover' }}
                                        alt='user-avatar' height='50' width='50' />
                                    :
                                    <span className='custom-user-avatar'>
                                        {getAvatarName(displayFullName)}
                                    </span>
                                }
                                <span
                                    className='ms-2 text-gray-600 d-none d-sm-block'>
                                    {displayFullName}
                                </span>
                            </div>
                            <FontAwesomeIcon icon={faAngleDown} className="text-gray-600 ms-2 d-none d-sm-block" />
                        </Dropdown.Toggle>
                        <Dropdown.Menu>
                            <div className='text-center pb-1 border-bottom mb-4'>
                                <div className='text-center text-decoration-none pb-5 w-100 align-items-center'>
                                    <div className='image image-circle w-100 image-tiny pb-5'>
                                        {avatarSrc && !avatarLoadFailed ?
                                            <img src={avatarSrc}
                                                onError={() => setAvatarLoadFailed(true)}
                                                className='img-fluid'
                                                style={{ objectFit: 'cover' }}
                                                height='50' width='50' alt='user-avatar' /> :
                                            <span className='user_avatar mx-auto'>
                                                {getAvatarName(displayFullName)}
                                            </span>
                                        }
                                    </div>
                                    <div className='d-flex flex-column'>
                                        <h3 className='text-gray-900'>
                                            {displayFullName}
                                        </h3>
                                        <h4 className='mb-0 fw-400 fs-6'>
                                            {displayEmail}
                                        </h4>
                                    </div>
                                </div>
                            </div>
                            <Dropdown.Item
                                onClick={(e) => onProfileClick(e)}
                                className='px-5 fs-6'>
                                <span className='dropdown-icon me-4 text-gray-600'>
                                    <FontAwesomeIcon icon={faUser} />
                                </span>
                                {getFormattedMessage('header.profile-menu.profile.label')}</Dropdown.Item>
                            <Dropdown.Item onClick={onClickDeleteModel}
                                className='px-5 fs-6'>
                                <span className='dropdown-icon me-4 text-gray-600'>
                                    <FontAwesomeIcon icon={faLock} />
                                </span>
                                {getFormattedMessage('header.profile-menu.change-password.label')}
                            </Dropdown.Item>
                            <Dropdown.Item onClick={onClickLanguageModel}
                                className='px-5 fs-6'>
                                <span className='dropdown-icon me-4 text-gray-600'>
                                    <FontAwesomeIcon icon={faLanguage} />
                                </span>
                                {getFormattedMessage('header.profile-menu.change-language.label')}
                            </Dropdown.Item>
                            {loginUser?.roles !== ROLES.SUPER_ADMIN && !allConfigData?.is_sub_user ? <Dropdown.Item onClick={onClickManageSubscription}
                                className='px-5 fs-6'>
                                <span className='dropdown-icon me-4 text-gray-600'>
                                    <FontAwesomeIcon icon={faMoneyBill} />
                                </span>
                                {getFormattedMessage('header.profile-menu.manage-subscriptions.label')}
                            </Dropdown.Item> : ''}
                            <Dropdown.Item onClick={(e) => onLogOut(e)}
                                className='px-5 fs-6'>
                                <span className='dropdown-icon me-4 text-gray-600'>
                                    <FontAwesomeIcon icon={faRightFromBracket} />
                                </span>
                                {getFormattedMessage('header.profile-menu.logout.label')}
                            </Dropdown.Item>
                        </Dropdown.Menu>
                    </Dropdown>
                </Nav>
            </div>
            {deleteModel === true &&
                <ChangePassword deleteModel={deleteModel} onClickDeleteModel={onClickDeleteModel} />}

            {languageModel === true &&
                <LanguageModel languageModel={languageModel} onClickLanguageModel={onClickLanguageModel} />}
            
            {storeWarningModal &&
                <StoreWarningModal storeWarningModal={storeWarningModal} handleLogoutButton={handleLogoutButton} />}

            {subscriptionWarningModal &&
                <SubscriptionWarningModal subscriptionWarningModal={subscriptionWarningModal} handleLogoutButton={handleLogoutButton} />}

            <PosRegisterModel showPosRegisterModel={showPosRegisterModel} onClickshowPosRegisterModel={onClickshowPosRegisterModel} />
        </Navbar>
    )
};

const mapStateToProps = (state) => {
    const { selectedLanguage, productQuantityReport } = state;
    return { selectedLanguage, productQuantityReport }
};

export default connect(mapStateToProps, { logoutAction, updateLanguage, productQuantityReportAction })(Header);
