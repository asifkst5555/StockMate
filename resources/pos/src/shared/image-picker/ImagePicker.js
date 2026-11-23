import React, { useEffect, useState } from 'react';
import { getFormattedMessage, placeholderText } from '../sharedMethod';
import { faPencil } from '@fortawesome/free-solid-svg-icons';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';

const ImagePicker = (props) => {
    const { imagePreviewUrl, handleImageChange, imageTitle, avtarName, user, required, accept } = props;
    let fileInput = React.createRef();
    const [imgError, setImgError] = useState(false);

    useEffect(() => {
        setImgError(false);
    }, [imagePreviewUrl]);

    const showImage = Boolean(imagePreviewUrl && !imgError);

    return (
        <div className='col-12'>
            <label
                className='form-label mb-4'>{imageTitle ? imageTitle : getFormattedMessage('globally.input.change-logo.tooltip')}:{required && <span className='required' />}
            </label>
            <div className='d-block'>
                <div
                    className='image-picker'>
                    <div
                        className={`image previewImage imagePreviewUrl ${showImage ? '' : "d-flex justify-content-center align-items-center"}`}>
                        {showImage ?
                            <img src={imagePreviewUrl} alt='img' width={75}
                                height={100}
                                style={{ objectFit: 'cover' }}
                                onError={() => setImgError(true)}
                                className='image image-circle image-mini h-100' />
                            : avtarName ?
                                <span className='custom-user-avatar w-100 h-100'>{avtarName}</span>
                                :
                                <img src={user ? user : null} alt='img' width={75} height={75}
                                    style={{ objectFit: 'cover' }}
                                    className='image image-circle image-mini h-100' />
                        }
                        <span
                            className='picker-edit rounded-circle text-gray-500 fs-small cursor-pointer'>
                            <input
                                className='upload-file'
                                title={`${imageTitle ? imageTitle : placeholderText('globally.input.change-logo.tooltip')}`}
                                type='file'
                                accept={accept || '.png, .jpg, .jpeg, .webp'}
                                onChange={(e) => handleImageChange(e)}
                                ref={fileInput}
                            />
                            <FontAwesomeIcon icon={faPencil} />
                        </span>
                    </div>
                </div>
            </div>
        </div>)
}

export default ImagePicker;