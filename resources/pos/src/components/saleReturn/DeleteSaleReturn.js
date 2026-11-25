import React, {useEffect, useRef, useState} from 'react';
import {connect} from 'react-redux';
import DeleteModel from '../../shared/action-buttons/DeleteModel';
import {deleteSaleReturn} from '../../store/action/salesReturnAction';
import {getFormattedMessage} from '../../shared/sharedMethod';

const DeleteSaleReturn = (props) => {
    const {deleteSaleReturn, onDelete, deleteModel, onClickDeleteModel} = props;
    const [isDeleting, setIsDeleting] = useState(false);
    const isDeletingRef = useRef(false);
    const isMountedRef = useRef(true);

    useEffect(() => {
        return () => {
            isMountedRef.current = false;
        };
    }, []);

    useEffect(() => {
        if (!deleteModel) {
            isDeletingRef.current = false;
            setIsDeleting(false);
        }
    }, [deleteModel]);

    const deleteSaleClick = async () => {
        if (isDeletingRef.current || !onDelete?.id) {
            return;
        }
        isDeletingRef.current = true;
        setIsDeleting(true);
        try {
            const ok = await deleteSaleReturn(onDelete.id);
            if (ok === true) {
                onClickDeleteModel(false);
            }
        } finally {
            isDeletingRef.current = false;
            if (isMountedRef.current) {
                setIsDeleting(false);
            }
        }
    };

    return (
        <div>
            {deleteModel && <DeleteModel onClickDeleteModel={onClickDeleteModel} deleteModel={deleteModel}
                                         deleteClick={deleteSaleClick} isDeleting={isDeleting}
                                         name={getFormattedMessage('sale-return.title')}/>}
        </div>
    )
};

export default connect(null, {deleteSaleReturn})(DeleteSaleReturn);
