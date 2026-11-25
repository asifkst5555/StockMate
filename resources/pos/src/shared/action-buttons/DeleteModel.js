import React, {useCallback, useEffect} from 'react';
import SweetAlert from 'react-bootstrap-sweetalert';
import {getFormattedMessage} from '../sharedMethod';
import remove from '../../assets/images/remove.png';

const DeleteModel = (props) => {
    const {onClickDeleteModel, deleteClick, name, isDeleting = false} = props;

    const handleCancel = useCallback(() => {
        if (!isDeleting) {
            onClickDeleteModel(false);
        }
    }, [isDeleting, onClickDeleteModel]);

    const escFunction = useCallback((event) => {
        if (event.keyCode === 27 && !isDeleting) {
            // User for Close the model on Escape
            onClickDeleteModel(false);
        }
    }, [isDeleting, onClickDeleteModel]);

    useEffect(() => {
        document.addEventListener('keydown', escFunction, false);
        return () => {
            document.removeEventListener('keydown', escFunction, false);
        };
    }, [escFunction]);

    return (
        <SweetAlert
            custom
            confirmBtnBsStyle='danger mb-3 fs-5 rounded'
            cancelBtnBsStyle='secondary mb-3 fs-5 rounded text-white'
            cancelBtnCssClass={isDeleting ? 'disabled pe-none' : ''}
            confirmBtnText={getFormattedMessage('delete-modal.yes-btn')}
            cancelBtnText={getFormattedMessage('delete-modal.no-btn')}
            title={getFormattedMessage('delete-modal.title')}
            onConfirm={deleteClick}
            onCancel={handleCancel}
            disabled={isDeleting}
            allowEscape={!isDeleting}
            closeOnClickOutside={!isDeleting}
            showCancel
            focusCancelBtn
            customIcon={remove}
        >
            <span className='sweet-text'>{getFormattedMessage('delete-modal.msg')} '{name}' ?</span>
        </SweetAlert>
    )
};
export default DeleteModel;
