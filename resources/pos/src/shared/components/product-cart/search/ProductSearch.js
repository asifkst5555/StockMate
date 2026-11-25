import React, {useState} from 'react';
import {connect, useDispatch} from 'react-redux';
import {ReactSearchAutocomplete} from 'react-search-autocomplete';
import {addToast} from '../../../../store/action/toastAction';
import {toastType} from '../../../../constants';
import {searchPurchaseProduct} from '../../../../store/action/purchaseProductAction';
import {getFormattedMessage, placeholderText} from '../../../sharedMethod';
import {FontAwesomeIcon} from "@fortawesome/react-fontawesome";
import {faSearch} from "@fortawesome/free-solid-svg-icons";

const ProductSearch = (props) => {
    const {
        values,
        products,
        updateProducts,
        setUpdateProducts,
        customProducts,
        searchPurchaseProduct,
        handleValidation,
        isAllProducts
    } = props;
    const [searchString, setSearchString] = useState("");
    const dispatch = useDispatch();
    const filterProducts = isAllProducts && values.warehouse_id ? products.map((item) => ({
        name: item.attributes.name, code: item.attributes.code, id: item.id
    })) : values.warehouse_id && products.filter((qty) => qty && qty.attributes && qty.attributes.stock && qty.attributes.stock.quantity > 0).map((item) => ({
        name: item.attributes.name, code: item.attributes.code, id: item.id
    }))

    const onProductSearch = (code) => {
        if (!values.warehouse_id) {
            handleValidation();
        } else {
            setSearchString(code);
            const newId = products.filter((item) => item.attributes.code === code || item.attributes.code === code.code).map((item) => item.id);
            const finalIdArrays = customProducts.map((id) => id.product_id);
            const finalId = finalIdArrays.filter((finalIdArray) => finalIdArray === newId[0]);
            if (finalId[0] !== undefined) {
                const targetId = finalId[0];
                const isAlreadyAdded = updateProducts.some(item =>
                    (item.product_id && item.product_id === targetId) ||
                    (item.id && item.id === targetId) ||
                    (item.code && (item.code === code || item.code === code?.code))
                );
                if (isAlreadyAdded) {
                    dispatch(addToast({
                        text: getFormattedMessage('globally.product-already-added.validate.message'),
                        type: toastType.ERROR
                    }));
                } else {
                    searchPurchaseProduct(newId[0]);
                    const pushArray = [...customProducts];
                    const newProduct = pushArray.find(element => element.product_id === targetId);
                    if (newProduct) {
                        setUpdateProducts([...updateProducts, newProduct]);
                    }
                }
                removeSearchClass();
                setSearchString("");
            }
        }
    }

    const handleOnSearch = (string) => {
        onProductSearch(string);
    }

    const handleOnSelect = (result) => {
        onProductSearch(result);
    }

    const formatResult = (item) => {
        return (
            <span onClick={(e) => e.stopPropagation()}>{item.code} ({item.name})</span>
        )
    }

    const removeSearchClass = () => {
        const customSearchEl = document.getElementsByClassName(`custom-search`)[0];
        const html = customSearchEl?.firstChild?.firstChild?.lastChild;
        if (html && html.style) {
            html.style.display = 'none';
        }
    }

    return (
        <div className='position-relative custom-search'>
            <ReactSearchAutocomplete
                items={filterProducts}
                onSearch={handleOnSearch}
                inputSearchString={searchString}
                fuseOptions={{keys: ['code', 'name']}}
                resultStringKeyName='code'
                placeholder={placeholderText('globally.search.field.label')}
                onSelect={handleOnSelect}
                formatResult={formatResult}
                showIcon={false}
                showClear={false}
            />
            <FontAwesomeIcon icon={faSearch}
                             className='d-flex align-items-center top-0 bottom-0 react-search-icon my-auto text-gray-600 position-absolute'/>
        </div>
    );
}

export default connect(null, {searchPurchaseProduct})(ProductSearch);
