import React, { useEffect, useRef, useState } from 'react';
import {connect} from 'react-redux';
import {deletetransfer} from '../../store/action/transfersAction';
import DeleteModel from '../../shared/action-buttons/DeleteModel';
import {getFormattedMessage} from '../../shared/sharedMethod';

const DeleteTransfer = (props) => {
    const {deletetransfer, onDelete, deleteModel, onClickDeleteModel} = props;
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

    const deleteClick = async () => {
        if (isDeletingRef.current || !onDelete?.id) {
            return;
        }
        isDeletingRef.current = true;
        setIsDeleting(true);
        try {
            const ok = await deletetransfer(onDelete.id);
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
                                         deleteClick={deleteClick} isDeleting={isDeleting}
                                         name={getFormattedMessage('transfer.title')}/>}
        </div>
    )
};

export default connect(null, {deletetransfer})(DeleteTransfer);
